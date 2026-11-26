<?php

namespace App\Kaspi;

use App\Jobs\PostKaspiOperationJob;
use App\Models\Client;
use App\Models\KaspiOperation;
use App\Models\Organization;
use App\Support\ClientAddress;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `check` and `pay` of one organization, after the sender, the uid and the
 * input have been checked.
 *
 * An answer other than 5 goes out only after its operation is saved: a
 * repeated `txn_id` must always find the first answer. No statement waits
 * for a lock; a try that meets one is repeated within the deadline.
 */
final class KaspiGateway
{
    public function __construct(private readonly PostKaspiOperation $posting) {}

    /**
     * Find the subscriber and the debt; a repeated `txn_id` gets the first answer.
     */
    public function check(Organization $organization, KaspiInput $input, KaspiDeadline $deadline, CarbonInterface $receivedAt): string
    {
        $known = $this->knownOperation($organization, $input);

        if ($known instanceof KaspiOperation) {
            return $this->repeat($known, $input, $deadline, $receivedAt);
        }

        if (! $organization->kaspi_enabled) {
            return $this->recordCheck($organization, $input, KaspiOperationState::Error, KaspiResponse::error($input->txnId), $deadline, $receivedAt, reason: 'Приём оплат выключен');
        }

        $client = $this->activeClient($organization, $input->account);

        if (! $client instanceof Client) {
            return $this->recordCheck($organization, $input, KaspiOperationState::NotFound, KaspiResponse::checkNotFound($input->txnId), $deadline, $receivedAt);
        }

        $debt = KaspiDebt::for($organization, $client);

        return $this->recordCheck(
            $organization,
            $input,
            KaspiOperationState::Found,
            KaspiResponse::checkFound($input->txnId, KaspiPayerName::for($client), $debt, $receivedAt->format('d.m.Y'), ClientAddress::of($client)),
            $deadline,
            $receivedAt,
            client: $client,
            debt: $debt,
        );
    }

    /**
     * Accept a payment and post it right away, or save it as in processing.
     */
    public function pay(Organization $organization, KaspiInput $input, KaspiDeadline $deadline, CarbonInterface $receivedAt): string
    {
        // A repeat is answered from the saved operation first, without any month lock.
        $known = $this->knownOperation($organization, $input);

        if ($known instanceof KaspiOperation) {
            return $this->repeat($known, $input, $deadline, $receivedAt);
        }

        if (! $organization->kaspi_enabled) {
            return KaspiResponse::error($input->txnId);
        }

        $client = $this->activeClient($organization, $input->account);

        if (! $client instanceof Client) {
            return KaspiResponse::error($input->txnId);
        }

        try {
            $operation = $deadline->retryWithoutWaiting(fn (): KaspiOperation => KaspiOperation::query()->create([
                'organization_id' => $organization->getKey(),
                'command' => KaspiCommand::Pay,
                'txn_id' => $input->txnId,
                'client_id' => $client->getKey(),
                'account' => $input->account,
                'sum' => $input->sum,
                'txn_date' => $input->txnDate,
                'received_at' => $receivedAt,
                'state' => KaspiOperationState::Pending,
                'state_reason' => 'Проводится',
                'last_result' => 4,
            ]));
        } catch (UniqueConstraintViolationException) {
            // The same txn_id arrived twice at once: the other request saved it first.
            $known = $this->knownOperation($organization, $input);

            return $known instanceof KaspiOperation ? $this->repeat($known, $input, $deadline, $receivedAt) : KaspiResponse::error($input->txnId);
        } catch (Throwable $exception) {
            // Nothing is saved — the deadline passed included — so Kaspi must
            // not think the payment is taken.
            self::reportUnlessLockTimeout($exception);

            return KaspiResponse::error($input->txnId);
        }

        try {
            $operation = $this->posting->handle($operation, $deadline, answeringKaspi: true);
            $body = (string) $operation->response;

            // A job may have posted the operation before this request got to
            // it: the row then keeps the 4 it was saved with. The 0 this answer
            // sends is written down like a repeat, without counting as one, and
            // folded in below.
            if ((int) $operation->last_result !== KaspiResponse::resultOf($body)) {
                $this->rememberFirstAnswer($operation, KaspiResponse::resultOf($body), $deadline, $receivedAt);
            }

            return $body;
        } catch (CannotPostKaspiOperation $exception) {
            $this->posting->rememberAttempt($operation, $exception->getMessage(), $deadline, $exception->wasAttempted());
        } catch (Throwable $exception) {
            report($exception);
            $this->posting->rememberAttempt($operation, 'Ошибка проводки, повтор автоматически', $deadline);
        } finally {
            // Repeats that came while this request posted are folded now if the row is free.
            KaspiRetryLog::fold((int) $operation->getKey(), $deadline);
        }

        $this->queuePosting($operation);

        // The operation is saved and committed, so the payment is taken: 4, not 5.
        return KaspiResponse::payPending($input->txnId);
    }

    /**
     * The answer to a repeated `txn_id` follows the current state of the
     * operation, whatever the repeat says. The repeat itself — its time, its
     * code and whether its data differ — is written to the repeat log at once,
     * which waits for no lock, and folded into the journal row when the row is
     * free (KaspiRetryLog). So the answer never waits for a posting in
     * progress, and no repeat or mismatch is lost.
     */
    private function repeat(KaspiOperation $operation, KaspiInput $input, KaspiDeadline $deadline, CarbonInterface $receivedAt): string
    {
        $isSameCommand = $operation->command === $input->command;

        $body = match (true) {
            ! $isSameCommand => KaspiResponse::error($input->txnId),
            $operation->state === KaspiOperationState::Pending => KaspiResponse::payPending($input->txnId),
            default => (string) $operation->response,
        };

        try {
            $deadline->withoutWaiting();

            KaspiRetryLog::record(
                $operation,
                mismatch: ! $isSameCommand || $this->differs($operation, $input),
                result: KaspiResponse::resultOf($body),
                receivedAt: $receivedAt,
            );
        } catch (QueryException $exception) {
            // The database refused even an insert that waits for nothing: the
            // answer is still the operation's, and the failure is reported.
            report($exception);
        }

        KaspiRetryLog::fold((int) $operation->getKey(), $deadline);

        return $body;
    }

    /**
     * The answer of the first request for an operation posted by someone else.
     * Bookkeeping only, like a repeat: an insert that waits for nothing.
     */
    private function rememberFirstAnswer(KaspiOperation $operation, int $result, KaspiDeadline $deadline, CarbonInterface $receivedAt): void
    {
        try {
            $deadline->withoutWaiting();

            KaspiRetryLog::recordFirstAnswer($operation, $result, $receivedAt);
        } catch (QueryException $exception) {
            report($exception);
        }
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
     * not saved within the deadline — a held subscriber row, a failed
     * database — Kaspi gets 5.
     */
    private function recordCheck(
        Organization $organization,
        KaspiInput $input,
        KaspiOperationState $state,
        string $body,
        KaspiDeadline $deadline,
        CarbonInterface $receivedAt,
        ?Client $client = null,
        ?string $debt = null,
        ?string $reason = null,
    ): string {
        try {
            $deadline->retryWithoutWaiting(fn (): KaspiOperation => KaspiOperation::query()->create([
                'organization_id' => $organization->getKey(),
                'command' => KaspiCommand::Check,
                'txn_id' => $input->txnId,
                'client_id' => $client?->getKey(),
                'account' => $input->account,
                'debt' => $debt,
                'received_at' => $receivedAt,
                'state' => $state,
                'state_reason' => $reason,
                'last_result' => KaspiResponse::resultOf($body),
                'response' => $body,
            ]));
        } catch (UniqueConstraintViolationException) {
            $known = $this->knownOperation($organization, $input);

            return $known instanceof KaspiOperation ? $this->repeat($known, $input, $deadline, $receivedAt) : KaspiResponse::error($input->txnId);
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
