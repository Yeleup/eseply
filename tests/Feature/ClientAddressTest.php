<?php

use App\Filament\Pages\MeterReadingEntry;
use App\Filament\Pages\PaymentDesk;
use App\Models\Client;
use App\Models\Meter;
use App\Models\Organization;
use App\Models\Receipt;
use App\Models\Region;
use App\Models\Street;
use App\Models\UtilityService;
use App\Reports\Concerns\FormatsReportValues;
use App\Reports\MeterReadingSheetReport;
use App\Reports\MissingMeterReadingsReport;
use App\Reports\NewClientAccountsReport;
use App\Support\ReceiptTemplateVariables;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The address a page or a report builds in its own private method, called
 * directly: the cases below cannot all be reached through the screens, and
 * the bytes must stay what each place showed before the common builder.
 */
function clientAddressFrom(object|string $owner, string $method, mixed ...$arguments): string
{
    return (new ReflectionMethod($owner, $method))->invoke(is_object($owner) ? $owner : null, ...$arguments);
}

test('адрес в кассе, вводе показаний, отчётах и квитанции — каждый по своему прежнему правилу', function (
    ?string $street,
    ?string $house,
    ?string $apartment,
    bool $withRegion,
    string $cashDesk,
    string $readingEntry,
    string $report,
): void {
    $organization = Organization::factory()->create();
    UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create(['name' => 'Тестовый район']);
    $client = Client::factory()->for($organization)->create([
        'region_id' => $withRegion ? $region->id : null,
        'street_id' => $street === null ? null : Street::factory()->create(['region_id' => $region->id, 'name' => $street])->id,
        'house' => $house,
        'apartment' => $apartment,
    ]);
    $meter = Meter::factory()->for($organization)->for($client)->create();
    $receipt = Receipt::factory()->for($organization)->create(['client_id' => $client->id]);
    $reportValues = new class
    {
        use FormatsReportValues;
    };

    expect((new PaymentDesk)->addressOf($client->fresh()))->toBe($cashDesk)
        ->and(clientAddressFrom(new MeterReadingEntry, 'formatAddress', $meter->fresh()))->toBe($readingEntry)
        ->and(clientAddressFrom($reportValues, 'formatClientAddress', $client->fresh()))->toBe($report)
        ->and(clientAddressFrom(new MissingMeterReadingsReport, 'formatAddress', $meter->fresh()))->toBe($report)
        ->and(clientAddressFrom(new MeterReadingSheetReport, 'formatAddress', $meter->fresh()))->toBe($report)
        ->and(clientAddressFrom(new NewClientAccountsReport, 'formatAddress', $client->fresh()))->toBe($report)
        ->and(ReceiptTemplateVariables::values($receipt->fresh(), 'Для абонента', now())['client_address'])->toBe($report);
})->with([
    'полный' => ['ул. Тестовая', '12', '5', true, 'ул. Тестовая, д. 12, кв. 5', 'ул. Тестовая, д. 12, кв. 5', 'Тестовый район, ул. Тестовая, д. 12, кв. 5'],
    'дом и квартира пустыми строками' => ['ул. Тестовая', '', '', true, 'ул. Тестовая', 'ул. Тестовая', 'Тестовый район, ул. Тестовая'],
    'дом и квартира «0»' => ['ул. Тестовая', '0', '0', true, 'ул. Тестовая, д. 0, кв. 0', 'ул. Тестовая, д. 0, кв. 0', 'Тестовый район, ул. Тестовая, д. 0, кв. 0'],
    // The cash desk skips what PHP takes for false; the others skip what filled() takes for blank.
    'улица «0» и дом' => ['0', '12', null, true, 'д. 12', '0, д. 12', 'Тестовый район, 0, д. 12'],
    'только улица «0»' => ['0', null, null, true, 'Адрес не указан', '0', 'Тестовый район, 0'],
    'улица из пробелов и дом' => ['   ', '12', null, true, '   , д. 12', 'д. 12', 'Тестовый район, д. 12'],
    'только улица из пробелов' => ['   ', null, null, true, '   ', 'Тестовый район', 'Тестовый район'],
    'без улицы, с домом' => [null, '12', null, true, 'д. 12', 'д. 12', 'Тестовый район, д. 12'],
    'только район' => [null, null, null, true, 'Адрес не указан', 'Тестовый район', 'Тестовый район'],
    'ничего' => [null, null, null, false, 'Адрес не указан', '-', '-'],
]);
