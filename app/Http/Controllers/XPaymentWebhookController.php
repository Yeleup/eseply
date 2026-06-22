<?php

namespace App\Http\Controllers;

use App\Actions\ApplyPaymentTransactionStatus;
use App\Http\Requests\XPaymentWebhookRequest;
use App\Models\PaymentTransaction;
use Illuminate\Http\JsonResponse;

class XPaymentWebhookController extends Controller
{
    public function __invoke(
        XPaymentWebhookRequest $request,
        ApplyPaymentTransactionStatus $applyPaymentTransactionStatus,
    ): JsonResponse {
        $this->assertValidSecret($request);

        $paymentTransaction = $this->findPaymentTransaction($request);

        if (! $paymentTransaction instanceof PaymentTransaction) {
            return response()->json([
                'message' => 'Payment transaction not found.',
            ], 404);
        }

        $applyPaymentTransactionStatus->handle($paymentTransaction, $request->validated());

        return response()->json([
            'ok' => true,
        ]);
    }

    private function assertValidSecret(XPaymentWebhookRequest $request): void
    {
        $expectedSecret = config('services.xpayment.webhook_secret');

        abort_unless(is_string($expectedSecret) && $expectedSecret !== '', 403);

        $actualSecret = (string) ($request->header('X-XPayment-Webhook-Secret')
            ?? $request->header('X-Webhook-Secret'));

        abort_unless(hash_equals($expectedSecret, $actualSecret), 403);
    }

    private function findPaymentTransaction(XPaymentWebhookRequest $request): ?PaymentTransaction
    {
        $merchantOrderId = $request->merchantOrderId();
        $paymentId = $request->paymentId();

        return PaymentTransaction::query()
            ->when(
                $merchantOrderId !== null,
                fn ($query) => $query->where('merchant_order_id', $merchantOrderId),
            )
            ->when(
                $merchantOrderId === null && $paymentId !== null,
                fn ($query) => $query->where('external_payment_id', $paymentId),
            )
            ->first();
    }
}
