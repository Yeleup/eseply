<?php

namespace App\Services\XPayment;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class XPaymentClient
{
    /**
     * @return array<string, mixed>
     */
    public function createPaymentLink(float|int|string $amount, string $merchantOrderId, string $idempotencyKey, string $apiKey): array
    {
        $response = $this->request($apiKey)
            ->withHeaders([
                'X-Idempotency-Key' => $idempotencyKey,
            ])
            ->post('/payments/link', [
                'amount' => (float) $amount,
                'merchant_order_id' => $merchantOrderId,
            ])
            ->throw();

        return $response->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function payment(string $paymentId, string $apiKey): array
    {
        $response = $this->request($apiKey)
            ->get("/payments/{$paymentId}")
            ->throw();

        return $response->json() ?? [];
    }

    private function request(string $apiKey): PendingRequest
    {
        if ($apiKey === '') {
            throw new RuntimeException('XPayment API key организации не настроен.');
        }

        return Http::baseUrl(rtrim((string) config('services.xpayment.base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withToken($apiKey)
            ->timeout((int) config('services.xpayment.timeout', 10))
            ->connectTimeout((int) config('services.xpayment.connect_timeout', 3))
            ->retry([100, 500, 1000]);
    }
}
