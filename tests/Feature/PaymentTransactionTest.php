<?php

use App\Actions\ApplyPaymentTransactionStatus;
use App\Actions\CreateKaspiPaymentTransaction;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\PaymentMethod;
use App\PaymentTransactionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.xpayment.base_url', 'https://api.xpayment.test/v1');
    config()->set('services.xpayment.webhook_secret', 'xpayment-global-webhook-secret');
});

test('creating kaspi qr stores a pending transaction without creating a payment', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.xpayment.test/v1/payments/link' => Http::response([
            'expire_date' => '2026-06-22T11:39:53.000+05:00',
            'payment_id' => 'xpay-uuid-1',
            'qr_token' => 'https://qr.kaspi.kz/test-token',
            'status' => 'QrTokenCreated',
        ]),
    ]);

    $organization = Organization::factory()->create([
        'xpayment_api_key' => 'xdev_org_key_1',
    ]);
    $client = Client::factory()->for($organization)->create();
    billingPeriodFor($organization, '202606');

    $paymentTransaction = app(CreateKaspiPaymentTransaction::class)->handle(
        client: $client,
        amount: 2500,
        payerPhone: '+77001234567',
        note: 'Оплата по QR',
    );

    expect($paymentTransaction->organization->is($organization))->toBeTrue()
        ->and($paymentTransaction->client->is($client))->toBeTrue()
        ->and($paymentTransaction->period)->toBe('202606')
        ->and($paymentTransaction->status)->toBe(PaymentTransactionStatus::Pending)
        ->and($paymentTransaction->external_payment_id)->toBe('xpay-uuid-1')
        ->and($paymentTransaction->qr_url)->toBe('https://qr.kaspi.kz/test-token')
        ->and($paymentTransaction->payer_phone)->toBe('+77001234567')
        ->and(Payment::query()->count())->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.xpayment.test/v1/payments/link'
        && $request->hasHeader('Authorization', 'Bearer xdev_org_key_1')
        && $request->hasHeader('X-Idempotency-Key')
        && $request['amount'] === 2500.0
        && $request['merchant_order_id'] === $paymentTransaction->merchant_order_id);
});

test('completed kaspi transaction creates one kaspi payment', function () {
    $organization = Organization::factory()->create();
    $client = Client::factory()->for($organization)->create();
    billingPeriodFor($organization, '202606');

    $paymentTransaction = PaymentTransaction::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202606',
            'amount' => 3100,
            'external_payment_id' => 'xpay-uuid-2',
            'note' => 'Kaspi клиента',
        ]);

    app(ApplyPaymentTransactionStatus::class)->handle($paymentTransaction, [
        'status' => 'completed',
        'payment_id' => 'xpay-uuid-2',
        'completed_at' => '2026-06-22T15:15:00+05:00',
    ]);

    app(ApplyPaymentTransactionStatus::class)->handle($paymentTransaction->refresh(), [
        'status' => 'completed',
        'payment_id' => 'xpay-uuid-2',
        'completed_at' => '2026-06-22T15:15:00+05:00',
    ]);

    $paymentTransaction->refresh();
    $payment = Payment::query()->sole();

    expect($paymentTransaction->status)->toBe(PaymentTransactionStatus::Completed)
        ->and($paymentTransaction->payment->is($payment))->toBeTrue()
        ->and($payment->method)->toBe(PaymentMethod::Kaspi)
        ->and($payment->external_provider)->toBe('xpayment')
        ->and($payment->external_payment_id)->toBe('xpay-uuid-2')
        ->and($payment->amount)->toBe('3100.00')
        ->and($payment->paid_at->toDateString())->toBe('2026-06-22')
        ->and($payment->note)->toBe('Kaspi / XPayment: Kaspi клиента')
        ->and(Payment::query()->count())->toBe(1);
});

test('xpayment webhook completes transaction by merchant order id', function () {
    $organization = Organization::factory()->create();
    $client = Client::factory()->for($organization)->create();
    billingPeriodFor($organization, '202606');

    $paymentTransaction = PaymentTransaction::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202606',
            'amount' => 4200,
            'external_payment_id' => 'xpay-uuid-3',
        ]);

    $this->postJson(route('webhooks.xpayment'), [
        'event' => 'payment.completed',
        'merchant_order_id' => $paymentTransaction->merchant_order_id,
        'payment_id' => 'xpay-uuid-3',
        'completed_at' => '2026-06-22T17:00:00+05:00',
    ], [
        'X-XPayment-Webhook-Secret' => 'xpayment-global-webhook-secret',
    ])->assertSuccessful()
        ->assertJson([
            'ok' => true,
        ]);

    $paymentTransaction->refresh();
    $payment = Payment::query()->sole();

    expect($paymentTransaction->status)->toBe(PaymentTransactionStatus::Completed)
        ->and($paymentTransaction->payment->is($payment))->toBeTrue()
        ->and($payment->method)->toBe(PaymentMethod::Kaspi)
        ->and($payment->amount)->toBe('4200.00');
});

test('xpayment webhook rejects invalid secret', function () {
    $organization = Organization::factory()->create();
    $client = Client::factory()->for($organization)->create();
    billingPeriodFor($organization, '202606');

    $paymentTransaction = PaymentTransaction::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202606',
        ]);

    $this->postJson(route('webhooks.xpayment'), [
        'event' => 'payment.completed',
        'merchant_order_id' => $paymentTransaction->merchant_order_id,
    ], [
        'X-XPayment-Webhook-Secret' => 'wrong-secret',
    ])->assertForbidden();

    expect(Payment::query()->count())->toBe(0);
});
