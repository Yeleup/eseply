<?php

use App\ClientType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('client type enum exposes labels for tariff selection', function () {
    expect(ClientType::labelFor(ClientType::Individual))->toBe('Физ. лицо')
        ->and(ClientType::labelFor(ClientType::SoleProprietor->value))->toBe('ИП')
        ->and(ClientType::labelFor(ClientType::Llp->value))->toBe('ТОО')
        ->and(ClientType::labelFor(ClientType::Commercial->value))->toBe('Коммерческие объекты')
        ->and(ClientType::labelFor(ClientType::Budget->value))->toBe('Бюджет');
});

test('client type enum rejects unknown values', function () {
    expect(ClientType::tryFrom('legal'))->toBeNull()
        ->and(ClientType::labelFor('unknown'))->toBeNull();
});
