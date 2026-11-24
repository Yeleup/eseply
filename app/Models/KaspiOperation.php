<?php

namespace App\Models;

use App\Kaspi\KaspiCommand;
use App\Kaspi\KaspiOperationState;
use Database\Factories\KaspiOperationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Kaspi operation (organization + txn_id) of the journal.
 *
 * The canonical data — subscriber, account, sum and Kaspi date — are written
 * once by the first accepted request and never change: a payment is always
 * posted from them, never from a repeated request.
 */
#[Fillable([
    'organization_id',
    'command',
    'txn_id',
    'client_id',
    'account',
    'sum',
    'txn_date',
    'debt',
    'received_at',
    'state',
    'state_reason',
    'last_result',
    'response',
    'payment_id',
    'posted_at',
    'retries_count',
    'last_retry_at',
    'retry_mismatch',
])]
class KaspiOperation extends Model
{
    /** @use HasFactory<KaspiOperationFactory> */
    use HasFactory;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isPay(): bool
    {
        return $this->command === KaspiCommand::Pay;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'command' => KaspiCommand::class,
            'state' => KaspiOperationState::class,
            'sum' => 'decimal:2',
            'debt' => 'decimal:2',
            'txn_date' => 'datetime',
            'received_at' => 'datetime',
            'posted_at' => 'datetime',
            'last_retry_at' => 'datetime',
            'last_result' => 'integer',
            'retries_count' => 'integer',
            'retry_mismatch' => 'boolean',
        ];
    }
}
