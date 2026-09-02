<?php

use App\Models\Client;
use App\Models\Meter;
use App\Models\Organization;
use App\Models\UtilityService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Every table that carries both `organization_id` and `utility_service_id`.
 *
 * @return list<string>
 */
function tablesGuardedByUtilityServiceOrganizationKey(): array
{
    return [
        'accruals',
        'clients',
        'meter_readings',
        'meters',
        'tariffs',
    ];
}

/**
 * The migration that adds the composite key, so its repair can be exercised on
 * its own. Only the repair is called, never `up()`, because the schema changes
 * of a migration commit the transaction the test runs in.
 */
function utilityServiceIntegrityMigration(): Migration
{
    return require database_path('migrations/2026_09_02_210120_enforce_utility_service_organization_integrity.php');
}

/**
 * Writes a row the way a bulk import does, past the composite key.
 *
 * @param  array<string, mixed>  $row
 */
function importRowPastForeignKeys(string $table, array $row): void
{
    Schema::withoutForeignKeyConstraints(function () use ($table, $row): void {
        DB::table($table)->insert($row);
    });
}

/**
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function importedClientRow(Organization $organization, array $attributes = []): array
{
    return array_replace([
        'organization_id' => $organization->getKey(),
        'account_number' => fake()->unique()->numerify('#######'),
        'name' => 'Иванов Иван',
        'client_type' => 'individual',
        'billing_type' => 'meter',
        'residents_count' => 1,
        'fixed_amount' => 0,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes);
}

test('database rejects a client whose utility service belongs to another organization', function () {
    $organization = Organization::factory()->create();
    UtilityService::factory()->for($organization)->create();
    $foreignUtilityService = UtilityService::factory()->for(Organization::factory()->create())->create();

    expect(fn () => DB::table('clients')->insert(importedClientRow($organization, [
        'utility_service_id' => $foreignUtilityService->getKey(),
    ])))->toThrow(QueryException::class);
});

test('database rejects moving a meter onto a utility service of another organization', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $foreignUtilityService = UtilityService::factory()->for(Organization::factory()->create())->create();

    $meter = Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->create();

    expect(fn () => DB::table('meters')
        ->where('id', $meter->getKey())
        ->update(['utility_service_id' => $foreignUtilityService->getKey()]))
        ->toThrow(QueryException::class);
});

test('database accepts a client attached to the utility service of its own organization', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    DB::table('clients')->insert(importedClientRow($organization, [
        'utility_service_id' => $utilityService->getKey(),
    ]));

    expect(DB::table('clients')->count())->toBe(1);
});

test('database accepts a client without a utility service', function () {
    $organization = Organization::factory()->create();

    DB::table('clients')->insert(importedClientRow($organization, [
        'utility_service_id' => null,
    ]));

    expect(DB::table('clients')->count())->toBe(1);
});

test('deleting an organization still removes its clients and its utility service', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    Client::factory()->for($organization)->for($utilityService)->create();

    $organization->delete();

    expect(DB::table('clients')->count())->toBe(0)
        ->and(DB::table('utility_services')->count())->toBe(0);
});

/**
 * The composite key restricts deletes, but `clients.utility_service_id` keeps its
 * own `set null` rule, so a deleted service clears the reference instead of
 * blocking. What matters is that the row keeps its organization.
 */
test('deleting a utility service clears the reference and keeps the organization of the row', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()->for($organization)->for($utilityService)->create();

    $utilityService->delete();

    $row = DB::table('clients')->where('id', $client->getKey())->sole();

    expect($row->utility_service_id)->toBeNull()
        ->and((int) $row->organization_id)->toBe($organization->getKey());
});

test('every table with a utility service carries the composite key', function (string $table) {
    $constraint = DB::table('information_schema.KEY_COLUMN_USAGE')
        ->where('TABLE_SCHEMA', DB::getDatabaseName())
        ->where('TABLE_NAME', $table)
        ->where('CONSTRAINT_NAME', $table.'_utility_service_organization_foreign')
        ->where('REFERENCED_TABLE_NAME', 'utility_services')
        ->orderBy('ORDINAL_POSITION')
        ->pluck('COLUMN_NAME')
        ->all();

    expect($constraint)->toBe(['utility_service_id', 'organization_id']);
})->with(tablesGuardedByUtilityServiceOrganizationKey());

test('migration points a row at the utility service of its own organization', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $foreignUtilityService = UtilityService::factory()->for(Organization::factory()->create())->create();

    importRowPastForeignKeys('clients', importedClientRow($organization, [
        'utility_service_id' => $foreignUtilityService->getKey(),
    ]));

    utilityServiceIntegrityMigration()->repairMisattributedRows();

    $utilityServiceId = DB::table('clients')
        ->where('organization_id', $organization->getKey())
        ->value('utility_service_id');

    expect((int) $utilityServiceId)->toBe($utilityService->getKey());
});

test('migration clears the utility service of a row whose organization has none', function () {
    $organization = Organization::factory()->create();
    $foreignUtilityService = UtilityService::factory()->for(Organization::factory()->create())->create();

    importRowPastForeignKeys('clients', importedClientRow($organization, [
        'utility_service_id' => $foreignUtilityService->getKey(),
    ]));

    utilityServiceIntegrityMigration()->repairMisattributedRows();

    $row = DB::table('clients')->where('organization_id', $organization->getKey())->sole();

    expect($row->utility_service_id)->toBeNull()
        ->and((int) $row->organization_id)->toBe($organization->getKey());
});

test('migration leaves a row that already points at its own utility service', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    DB::table('clients')->insert(importedClientRow($organization, [
        'utility_service_id' => $utilityService->getKey(),
    ]));

    utilityServiceIntegrityMigration()->repairMisattributedRows();

    $utilityServiceId = DB::table('clients')
        ->where('organization_id', $organization->getKey())
        ->value('utility_service_id');

    expect((int) $utilityServiceId)->toBe($utilityService->getKey());
});
