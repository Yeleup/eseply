<?php

use App\Models\Organization;
use App\Models\Receipt;
use App\Support\ReceiptTemplateVariables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('values map covers every scalar variable with formatted data', function () {
    $organization = Organization::factory()->create([
        'name' => 'ТОО Водоканал',
        'bin_iin' => '123456789012',
        'address' => 'Алматы, Абая 10',
    ]);
    $receipt = Receipt::factory()->for($organization)->create([
        'receipt_number' => '202605-100010',
        'account_number' => '100010',
        'client_name' => 'Иванов Иван',
        'billing_type' => 'fixed',
        'amount' => 1800,
        'paid_amount' => 300,
        'opening_balance' => 0,
        'closing_balance' => 1500,
    ]);

    $generatedAt = Carbon::create(2026, 8, 12, 10, 30);
    $values = ReceiptTemplateVariables::values($receipt->fresh(), 'Для абонента', $generatedAt);

    expect($values['organization_name'])->toBe('ТОО Водоканал')
        ->and($values['organization_bin'])->toBe('123456789012')
        ->and($values['account_number'])->toBe('100010')
        ->and($values['client_name'])->toBe('Иванов Иван')
        ->and($values['billing_type'])->toBe('Фиксированная сумма')
        ->and($values['receipt_number'])->toBe('202605-100010')
        ->and($values['amount'])->toBe('1 800.00 KZT')
        ->and($values['paid_amount'])->toBe('300.00 KZT')
        ->and($values['opening_balance'])->toBe('0.00 KZT')
        ->and($values['amount_due'])->toBe('1 500.00 KZT')
        ->and($values['copy_title'])->toBe('Для абонента')
        ->and($values['generated_at'])->toBe('12.08.2026 10:30');

    $scalarKeys = array_keys(array_diff_key(
        ReceiptTemplateVariables::labels(),
        array_flip(['meters_table', 'logo', 'qr']),
    ));

    expect(array_keys($values))->toEqualCanonicalizing($scalarKeys);
});

test('fragments render meters table and organization images', function () {
    $organization = Organization::factory()->create();
    $receipt = Receipt::factory()->for($organization)->create();

    $fragments = ReceiptTemplateVariables::fragments($receipt->fresh(), [
        [
            'meter_number' => 'MTR-1',
            'previous_reading' => '100',
            'current_reading' => '120',
            'consumption' => '20',
            'tariff_price' => '90.00 KZT',
            'amount' => '1 800.00 KZT',
        ],
    ], now());

    expect($fragments['meters_table'])->toContain('Счётчики')
        ->and($fragments['meters_table'])->toContain('MTR-1')
        ->and($fragments['meters_table'])->toContain('Итого')
        ->and($fragments['logo'])->toBe('')
        ->and($fragments['qr'])->toBe('');
});
