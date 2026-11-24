<?php

namespace App\Actions;

use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Payment;
use App\PaymentMethod;
use Illuminate\Support\Facades\DB;

/**
 * A payment entered by hand — at the desk, in the subscriber card or in
 * «Оплаты» — always cash, in the current editable month.
 *
 * The subscriber row is locked first, before any other read, as the Kaspi
 * posting does: two payments of one subscriber at the same moment then
 * recalculate the receipt one after the other and no sum is lost.
 */
final class RecordManualPayment
{
    /**
     * @param  array{amount: mixed, paid_at?: mixed, note?: mixed}  $data
     */
    public function handle(Organization $organization, int $clientId, array $data, ?int $receivedByUserId): Payment
    {
        return DB::transaction(function () use ($organization, $clientId, $data, $receivedByUserId): Payment {
            Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($clientId)
                ->lockForUpdate()
                ->firstOrFail();

            $billingPeriod = BillingPeriod::requireCurrentEditableFor($organization);

            return Payment::query()->create([
                'organization_id' => $organization->getKey(),
                'client_id' => $clientId,
                'billing_period_id' => $billingPeriod->getKey(),
                'method' => PaymentMethod::Cash->value,
                'received_by_user_id' => $receivedByUserId,
                'amount' => $data['amount'],
                // The desk always sends a date; the other forms may leave it empty.
                'paid_at' => array_key_exists('paid_at', $data) ? $data['paid_at'] : today(),
                'note' => $data['note'] ?? null,
            ]);
        }, attempts: 3);
    }
}
