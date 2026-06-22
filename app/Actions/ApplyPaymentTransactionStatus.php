<?php

namespace App\Actions;

use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\PaymentMethod;
use App\PaymentTransactionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ApplyPaymentTransactionStatus
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(PaymentTransaction $paymentTransaction, array $payload = []): PaymentTransaction
    {
        return DB::transaction(function () use ($paymentTransaction, $payload): PaymentTransaction {
            $lockedTransaction = PaymentTransaction::query()
                ->whereKey($paymentTransaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $status = PaymentTransactionStatus::fromXPaymentStatus(
                $this->payloadString($payload, 'status') ?? $this->payloadString($payload, 'data.status'),
                $this->payloadString($payload, 'event') ?? $this->payloadString($payload, 'data.event'),
            );

            $externalPaymentId = $this->payloadString($payload, 'payment_id')
                ?? $this->payloadString($payload, 'data.payment_id')
                ?? $lockedTransaction->external_payment_id;

            $completedAt = $this->parseDateTime(data_get($payload, 'completed_at'))
                ?? $this->parseDateTime(data_get($payload, 'data.completed_at'));
            $cancelledAt = $this->parseDateTime(data_get($payload, 'cancelled_at'))
                ?? $this->parseDateTime(data_get($payload, 'data.cancelled_at'));

            $lockedTransaction->forceFill([
                'status' => $status,
                'external_payment_id' => $externalPaymentId,
                'completed_at' => $status === PaymentTransactionStatus::Completed
                    ? ($completedAt ?? $lockedTransaction->completed_at ?? now())
                    : $lockedTransaction->completed_at,
                'failed_at' => $status === PaymentTransactionStatus::Failed
                    ? ($lockedTransaction->failed_at ?? now())
                    : $lockedTransaction->failed_at,
                'cancelled_at' => $status === PaymentTransactionStatus::Cancelled
                    ? ($cancelledAt ?? $lockedTransaction->cancelled_at ?? now())
                    : $lockedTransaction->cancelled_at,
                'raw_payload' => $payload === [] ? $lockedTransaction->raw_payload : $payload,
            ])->save();

            if (! $status->shouldCreatePayment() || $lockedTransaction->payment_id) {
                return $lockedTransaction->refresh();
            }

            $payment = Payment::query()->create([
                'organization_id' => $lockedTransaction->organization_id,
                'client_id' => $lockedTransaction->client_id,
                'billing_period_id' => $lockedTransaction->billing_period_id,
                'method' => PaymentMethod::Kaspi,
                'external_provider' => $lockedTransaction->provider?->value,
                'external_payment_id' => $lockedTransaction->external_payment_id,
                'amount' => $lockedTransaction->amount,
                'paid_at' => ($lockedTransaction->completed_at ?? now())->toDateString(),
                'note' => $this->paymentNote($lockedTransaction),
            ]);

            $lockedTransaction->forceFill([
                'payment_id' => $payment->getKey(),
            ])->save();

            return $lockedTransaction->refresh();
        });
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

    private function paymentNote(PaymentTransaction $paymentTransaction): string
    {
        $note = trim((string) $paymentTransaction->note);

        if ($note === '') {
            return 'Kaspi / XPayment';
        }

        return "Kaspi / XPayment: {$note}";
    }
}
