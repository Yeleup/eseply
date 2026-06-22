<?php

namespace App\Actions;

use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\PaymentTransaction;
use App\PaymentTransactionProvider;
use App\PaymentTransactionStatus;
use App\Services\XPayment\XPaymentClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CreateKaspiPaymentTransaction
{
    public function __construct(private readonly XPaymentClient $xPaymentClient) {}

    public function handle(Client $client, float|int|string $amount, string $payerPhone, ?string $note = null): PaymentTransaction
    {
        $payerPhone = trim($payerPhone);

        if ($payerPhone === '') {
            throw new RuntimeException('Укажите телефон плательщика Kaspi для удалённой оплаты.');
        }

        $client->loadMissing('organization');

        $apiKey = $client->organization?->xpayment_api_key;

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('У организации не настроен XPayment API key.');
        }

        $billingPeriod = BillingPeriod::requireCurrentEditableFor($client->organization_id);
        $merchantOrderId = 'esepteu-'.Str::uuid();
        $idempotencyKey = (string) Str::uuid();

        $paymentTransaction = PaymentTransaction::query()->create([
            'organization_id' => $client->organization_id,
            'client_id' => $client->getKey(),
            'billing_period_id' => $billingPeriod->getKey(),
            'provider' => PaymentTransactionProvider::XPayment,
            'merchant_order_id' => $merchantOrderId,
            'idempotency_key' => $idempotencyKey,
            'amount' => $amount,
            'status' => PaymentTransactionStatus::Pending,
            'payer_phone' => $payerPhone,
            'note' => $note,
        ]);

        try {
            $response = $this->xPaymentClient->createPayment(
                amount: $amount,
                merchantOrderId: $merchantOrderId,
                payerPhone: $payerPhone,
                comment: $note,
                idempotencyKey: $idempotencyKey,
                apiKey: $apiKey,
            );
        } catch (Throwable $exception) {
            $paymentTransaction->forceFill([
                'status' => PaymentTransactionStatus::Failed,
                'failed_at' => now(),
                'raw_payload' => [
                    'error' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            ])->save();

            throw $exception;
        }

        $status = PaymentTransactionStatus::fromXPaymentStatus($this->payloadString($response, 'status'));

        $paymentTransaction->forceFill([
            'status' => $status,
            'external_payment_id' => $this->payloadString($response, 'payment_id'),
            'qr_url' => null,
            'expires_at' => $this->parseDateTime($response['expire_date'] ?? null),
            'completed_at' => $status === PaymentTransactionStatus::Completed ? now() : null,
            'failed_at' => $status === PaymentTransactionStatus::Failed ? now() : null,
            'cancelled_at' => $status === PaymentTransactionStatus::Cancelled ? now() : null,
            'raw_payload' => $response,
        ])->save();

        if ($status->shouldCreatePayment()) {
            app(ApplyPaymentTransactionStatus::class)->handle($paymentTransaction, $response);
        }

        return $paymentTransaction->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadString(array $payload, string $key): ?string
    {
        $value = data_get($payload, $key);

        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        if (! is_scalar($value) || blank((string) $value)) {
            return null;
        }

        return CarbonImmutable::parse((string) $value);
    }
}
