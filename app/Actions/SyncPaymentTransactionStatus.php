<?php

namespace App\Actions;

use App\Models\PaymentTransaction;
use App\Services\XPayment\XPaymentClient;
use RuntimeException;

class SyncPaymentTransactionStatus
{
    public function __construct(
        private readonly XPaymentClient $xPaymentClient,
        private readonly ApplyPaymentTransactionStatus $applyPaymentTransactionStatus,
    ) {}

    public function handle(PaymentTransaction $paymentTransaction): PaymentTransaction
    {
        if (! $paymentTransaction->external_payment_id) {
            return $paymentTransaction->refresh();
        }

        $paymentTransaction->loadMissing('organization');

        $apiKey = $paymentTransaction->organization?->xpayment_api_key;

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('У организации не настроен XPayment API key.');
        }

        $payload = $this->xPaymentClient->payment($paymentTransaction->external_payment_id, $apiKey);

        return $this->applyPaymentTransactionStatus->handle($paymentTransaction, $payload);
    }
}
