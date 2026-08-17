<?php

use App\BalanceAdjustmentType;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Every table that carries both `organization_id` and `billing_period_id`.
 *
 * @return list<string>
 */
function tablesGuardedByBillingPeriodOrganizationKey(): array
{
    return [
        'accruals',
        'balance_adjustments',
        'billing_period_closure_errors',
        'meter_readings',
        'payment_transactions',
        'payments',
        'receipts',
    ];
}

test('database rejects a balance adjustment whose billing period belongs to another organization', function () {
    $organization = Organization::factory()->create();
    $client = Client::factory()->for($organization)->create();
    $foreignBillingPeriod = billingPeriodFor(Organization::factory()->create(), '202606');

    expect(fn () => DB::table('balance_adjustments')->insert([
        'organization_id' => $organization->getKey(),
        'client_id' => $client->getKey(),
        'billing_period_id' => $foreignBillingPeriod->getKey(),
        'type' => BalanceAdjustmentType::OpeningBalance->value,
        'amount' => 1620,
        'adjusted_at' => '2026-06-03',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('database rejects moving an adjustment onto a billing period of another organization', function () {
    $organization = Organization::factory()->create();
    $client = Client::factory()->for($organization)->create();
    $ownBillingPeriod = billingPeriodFor($organization, '202606');
    $foreignBillingPeriod = billingPeriodFor(Organization::factory()->create(), '202606');

    $id = DB::table('balance_adjustments')->insertGetId([
        'organization_id' => $organization->getKey(),
        'client_id' => $client->getKey(),
        'billing_period_id' => $ownBillingPeriod->getKey(),
        'type' => BalanceAdjustmentType::OpeningBalance->value,
        'amount' => 1620,
        'adjusted_at' => '2026-06-03',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('balance_adjustments')
        ->where('id', $id)
        ->update(['billing_period_id' => $foreignBillingPeriod->getKey()]))
        ->toThrow(QueryException::class);
});

test('database accepts an adjustment attached to a billing period of its own organization', function () {
    $organization = Organization::factory()->create();
    $client = Client::factory()->for($organization)->create();
    $ownBillingPeriod = billingPeriodFor($organization, '202606');

    DB::table('balance_adjustments')->insert([
        'organization_id' => $organization->getKey(),
        'client_id' => $client->getKey(),
        'billing_period_id' => $ownBillingPeriod->getKey(),
        'type' => BalanceAdjustmentType::OpeningBalance->value,
        'amount' => 1620,
        'adjusted_at' => '2026-06-03',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('balance_adjustments')->count())->toBe(1);
});

test('every table with a billing period carries the composite key', function (string $table) {
    $constraint = DB::table('information_schema.KEY_COLUMN_USAGE')
        ->where('TABLE_SCHEMA', DB::getDatabaseName())
        ->where('TABLE_NAME', $table)
        ->where('CONSTRAINT_NAME', $table.'_period_organization_foreign')
        ->where('REFERENCED_TABLE_NAME', 'billing_periods')
        ->orderBy('ORDINAL_POSITION')
        ->pluck('COLUMN_NAME')
        ->all();

    expect($constraint)->toBe(['billing_period_id', 'organization_id']);
})->with(tablesGuardedByBillingPeriodOrganizationKey());
