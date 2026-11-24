<?php

namespace App\Kaspi;

use App\Jobs\PostKaspiOperationJob;
use App\Models\Client;
use App\Models\KaspiOperation;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `check` and `pay` of one organization, after the IP, the uid and the input
 * have been checked.
 *
 * An answer other than 5 goes out only after its operation is saved: a
 * repeated `txn_id` must always find the first answer.
 */
final class KaspiGateway
{
    public function __construct(private readonly PostKaspiOperation $posting) {}

    /**
     * Find the subscriber and the debt; a repeated `txn_id` gets the first answer.
     */
    public function check(Organization $organization, KaspiInput $input, KaspiDeadline $deadline): string
    {
        $known = $this->knownOperation($organization, $input);

        if ($known instanceof KaspiOperation) {
            return $this->repeat($known, $input, $deadline);
        }

        if (! $organization->kaspi_enabled) {
            return $this->recordCheck($organization, $input, KaspiOperationState::Error, KaspiResponse::error($input->txnId), reason: 'Приём оплат выключен', deadline: $deadline);
        }

        $client = $this->activeClient($organization, $input->account);

        if (! $client instanceof Client) {
            return $this->recordCheck($organization, $input, KaspiOperationState::NotFound, KaspiResponse::checkNotFound($input->txnId), deadline: $deadline);
        }

        $debt = KaspiDebt::for($organization, $client);

        return $this->recordCheck(
            $organization,
            $input,
            KaspiOperationState::Found,
            KaspiResponse::checkFound($input->txnId, KaspiPayerName::for($client), $debt, today()->format('d.m.Y')),
            client: $client,
            debt: $debt,
            deadline: $deadline,
        );
    }

    /**
     * Accept a payment and post it right away, or save it as in processing.
     */
    public function pay(Organization $organization, KaspiInput $input, KaspiDeadline $deadline): string
    {
        // A repeat is answered from the saved operation first, without any month lock.
        $known = $this->knownOperation($organization, $input);

        if ($known instanceof KaspiOperation) {
            return $this->repeat($known, $input, $deadline);
        }

        if (! $organization->kaspi_enabled) {
            return KaspiResponse::error($input->txnId);
        }

        $client = $this->activeClient($organization, $input->account);

        if (! $client instanceof Client) {
            return KaspiResponse::error($input->txnId);
        }

        try {
            $deadline->limitRowLockWait();

            $operation = KaspiOperation::query()->create([
                'organization_id' => $organization->getKey(),
                'command' => KaspiCommand::Pay,
                'txn_id' => $input->txnId,
                'client_id' => $client->getKey(),
                'account' => $input->account,
                'sum' => $input->sum,
                'txn_date' => $input->txnDate,
                'received_at' => now(),
                'state' => KaspiOperationState::Pending,
                'state_reason' => 'Проводится',
                'last_result' => 4,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The same txn_id arrived twice at once: the other request saved it first.
            $known = $this->knownOperation($organization, $input);

            return $known instanceof KaspiOperation ? $this->repeat($known, $input, $deadline) : KaspiResponse::error($input->txnId);
        } catch (Throwable $exception) {
            // Nothing is saved — a lock wait past the deadline included — so
            // Kaspi must not think the payment is taken.
            self::reportUnlessLockTimeout($exception);

            return KaspiResponse::error($input->txnId);
        }

        try {
            $operation = $this->posting->handle($operation, $deadline, answeringKaspi: true);

            return (string) $operation->response;
        } catch (KaspiSumOutOfRange) {
            // The operation is an error now: Kaspi gets 5 and nothing is posted.
            return KaspiResponse::error($input->txnId);
        } catch (CannotPostKaspiOperation $exception) {
            $this->posting->rememberReason($operation, $exception->getMessage(), $deadline);
        } catch (Throwable $exception) {
            report($exception);
            $this->posting->rememberReason($operation, 'Ошибка проводки, повтор автоматически', $deadline);
        }

        $this->queuePosting($operation);

        // The operation is saved and committed, so the payment is taken: 4, not 5.
        return KaspiResponse::payPending($input->txnId);
    }

    /**
     * The answer to a repeated `txn_id` follows the current state of the
     * operation, whatever the repeat says; different data in the repeat only
     * mark the operation. That bookkeeping never holds the answer past the
     * deadline: a row locked by a posting in progress is not waited for long.
     */
    private function repeat(KaspiOperation $operation, KaspiInput $input, KaspiDeadline $deadline): string
    {
        $isSameCommand = $operation->command === $input->command;

        $body = match (true) {
            ! $isSameCommand => KaspiResponse::error($input->txnId),
            $operation->state === KaspiOperationState::Pending => KaspiResponse::payPending($input->txnId),
            default => (string) $operation->response,
        };

        try {
            $deadline->limitBookkeepingWait();

            KaspiOperation::query()
                ->whereKey($operation->getKey())
                ->update([
                    'retries_count' => DB::raw('retries_count + 1'),
                    'last_retry_at' => now(),
                    'last_result' => KaspiResponse::resultOf($body),
                    // Once marked, the mark stays, whatever later repeats bring.
                    'retry_mismatch' => ! $isSameCommand || $this->differs($operation, $input)
                        ? true
                        : DB::raw('retry_mismatch'),
                    'updated_at' => now(),
                ]);
        } catch (QueryException $exception) {
            // The answer does not depend on this bookkeeping.
            Log::info('Kaspi: повтор не записан в журнал', [
                'kaspi_operation_id' => $operation->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }

        return $body;
    }

    private function differs(KaspiOperation $operation, KaspiInput $input): bool
    {
        if ($operation->account !== $input->account) {
            return true;
        }

        if (! $operation->isPay()) {
            return false;
        }

        return (string) $operation->sum !== $input->sum
            || $operation->txn_date?->format('YmdHis') !== $input->txnDate?->format('YmdHis');
    }

    /**
     * The answer of a `check` goes out only once it is in the journal: a
     * repeat must get the same answer, never a new calculation. If the row is
     * not saved in time — a locked subscriber row, a failed database — Kaspi
     * gets 5.
     */
    private function recordCheck(
        Organization $organization,
        KaspiInput $input,
        KaspiOperationState $state,
        string $body,
        KaspiDeadline $deadline,
        ?Client $client = null,
        ?string $debt = null,
        ?string $reason = null,
    ): string {
        try {
            $deadline->limitRowLockWait();

            KaspiOperation::query()->create([
                'organization_id' => $organization->getKey(),
                'command' => KaspiCommand::Check,
                'txn_id' => $input->txnId,
                'client_id' => $client?->getKey(),
                'account' => $input->account,
                'debt' => $debt,
                'received_at' => now(),
                'state' => $state,
                'state_reason' => $reason,
                'last_result' => KaspiResponse::resultOf($body),
                'response' => $body,
            ]);
        } catch (UniqueConstraintViolationException) {
            $known = $this->knownOperation($organization, $input);

            return $known instanceof KaspiOperation ? $this->repeat($known, $input, $deadline) : KaspiResponse::error($input->txnId);
        } catch (Throwable $exception) {
            self::reportUnlessLockTimeout($exception);

            return KaspiResponse::error($input->txnId);
        }

        return $body;
    }

    private function knownOperation(Organization $organization, KaspiInput $input): ?KaspiOperation
    {
        return KaspiOperation::query()
            ->where('organization_id', $organization->getKey())
            ->where('txn_id', $input->txnId)
            ->first();
    }

    /**
     * An inactive subscriber does not exist for new Kaspi operations.
     */
    private function activeClient(Organization $organization, string $account): ?Client
    {
        return Client::query()
            ->where('organization_id', $organization->getKey())
            ->where('account_number', $account)
            ->where('status', 'active')
            ->first();
    }

    private function queuePosting(KaspiOperation $operation): void
    {
        try {
            PostKaspiOperationJob::dispatch((int) $operation->getKey())
                ->delay(now()->addSeconds((int) (config('kaspi.posting.backoff')[0] ?? 10)));
        } catch (Throwable $exception) {
            // The scheduler picks the operation up within a minute anyway.
            Log::warning('Kaspi: задание проводки не поставлено в очередь', [
                'kaspi_operation_id' => $operation->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private static function reportUnlessLockTimeout(Throwable $exception): void
    {
        if ($exception instanceof CannotPostKaspiOperation
            || ($exception instanceof QueryException && KaspiDeadline::isLockTimeout($exception))) {
            Log::info('Kaspi: операция не записана, не дождались блокировки', ['error' => $exception->getMessage()]);

            return;
        }

        report($exception);
    }
}
