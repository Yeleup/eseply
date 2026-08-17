<?php

namespace App\Actions;

use App\BalanceAdjustmentType;
use App\BillingPeriodStatus;
use App\ClientType;
use App\Models\Accrual;
use App\Models\BalanceAdjustment;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\Meter;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Models\UtilityService;
use App\Support\BillingClosureIssue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class CloseBillingMonth
{
    /**
     * Number of active clients resolved per batch of database round trips.
     *
     * Closing reads and writes every active client of the organization, so all
     * per client data is loaded one batch at a time instead of one client at a
     * time. This keeps the query count flat as the organization grows.
     */
    public const int CHUNK_SIZE = 500;

    /**
     * Claim the billing period and calculate it in one call.
     *
     * @return array{active:int, created:int, skipped:int, failed:int, errors:list<array{client_id:int, account_number:string, client_name:string, message:string}>}
     */
    public function handle(Organization $organization, string|BillingPeriod $period, ?User $closedBy = null): array
    {
        $closedBy = $this->actingUser($closedBy);

        $billingPeriod = $this->claim($organization, $period, $closedBy);

        return $this->run($organization, $billingPeriod, $closedBy);
    }

    /**
     * Reserve the billing period for closing.
     *
     * The reservation is committed on its own so that a concurrent request sees
     * the `processing` status instead of waiting on a row lock for the whole
     * calculation.
     */
    public function claim(Organization $organization, string|BillingPeriod $period, ?User $startedBy = null): BillingPeriod
    {
        $startedBy = $this->actingUser($startedBy);

        return DB::transaction(function () use ($organization, $period, $startedBy): BillingPeriod {
            $billingPeriod = $this->lockedBillingPeriod($organization, $period, $startedBy);

            $this->ensureCanClose($billingPeriod);

            $billingPeriod->markProcessing();

            return $billingPeriod;
        }, attempts: 5);
    }

    /**
     * Calculate a billing period that has already been claimed for closing.
     *
     * A billing period is never left in `processing` after a technical failure,
     * otherwise no operator could start closing it again.
     *
     * @return array{active:int, created:int, skipped:int, failed:int, errors:list<array{client_id:int, account_number:string, client_name:string, message:string}>}
     */
    public function run(Organization $organization, BillingPeriod $billingPeriod, ?User $closedBy = null): array
    {
        try {
            return $this->calculate($organization, $billingPeriod, $this->actingUser($closedBy));
        } catch (Throwable $exception) {
            $billingPeriod->markFailed(
                $this->emptySummary(),
                'Закрытие расчётного месяца прервано технической ошибкой.',
            );

            throw $exception;
        }
    }

    /**
     * @return array{active:int, created:int, skipped:int, failed:int, errors:list<array{client_id:int, account_number:string, client_name:string, message:string}>}
     */
    private function calculate(Organization $organization, BillingPeriod $billingPeriod, ?User $closedBy): array
    {
        $periodStart = CarbonImmutable::instance($billingPeriod->starts_on)->startOfMonth();

        $organization->loadMissing('utilityService');
        $utilityService = $organization->utilityService;

        $tariffs = $this->activeTariffsByClientType($organization, $utilityService, $periodStart);

        /**
         * The active clients are resolved once so both passes below see exactly
         * the same set even if clients are created while closing runs.
         *
         * @var list<int> $activeClientIds
         */
        $activeClientIds = $organization->clients()
            ->where('status', 'active')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $summary = $this->emptySummary();
        $summary['active'] = count($activeClientIds);

        $closureErrors = [];

        /** First pass: prove every active client can be calculated before writing anything. */
        foreach (array_chunk($activeClientIds, self::CHUNK_SIZE) as $clientIdChunk) {
            $clients = $this->clientChunk($clientIdChunk);
            $closedClientIds = $this->closedClientIds($billingPeriod, $clientIdChunk);
            $metersByClient = $this->activeMetersByClient($organization, $billingPeriod, $utilityService, $clientIdChunk);

            foreach ($clients as $client) {
                if (isset($closedClientIds[$client->id])) {
                    $summary['skipped']++;

                    continue;
                }

                $calculation = $this->calculation($client, $utilityService, $periodStart, $tariffs, $metersByClient);

                if ($calculation instanceof BillingClosureIssue) {
                    $this->recordError($summary, $closureErrors, $client, $calculation);
                }
            }
        }

        if ($summary['failed'] > 0) {
            $billingPeriod->markFailed($summary, 'Не все активные абоненты были рассчитаны.', $closureErrors);

            return $summary;
        }

        /** Second pass: write the accruals of the whole organization atomically. */
        $summary['created'] = DB::transaction(function () use (
            $organization,
            $billingPeriod,
            $utilityService,
            $periodStart,
            $tariffs,
            $activeClientIds,
            $summary,
            $closedBy,
        ): int {
            $created = 0;

            foreach (array_chunk($activeClientIds, self::CHUNK_SIZE) as $clientIdChunk) {
                $created += $this->createAccrualChunk(
                    $organization,
                    $billingPeriod,
                    $utilityService,
                    $periodStart,
                    $tariffs,
                    $clientIdChunk,
                );
            }

            $billingPeriod->markClosed([...$summary, 'created' => $created], $closedBy);

            return $created;
        }, attempts: 5);

        return $summary;
    }

    /**
     * @param  array<string, Tariff>  $tariffs
     * @param  list<int>  $clientIdChunk
     */
    private function createAccrualChunk(
        Organization $organization,
        BillingPeriod $billingPeriod,
        ?UtilityService $utilityService,
        CarbonImmutable $periodStart,
        array $tariffs,
        array $clientIdChunk,
    ): int {
        $clients = $this->clientChunk($clientIdChunk);
        $closedClientIds = $this->closedClientIds($billingPeriod, $clientIdChunk);
        $metersByClient = $this->activeMetersByClient($organization, $billingPeriod, $utilityService, $clientIdChunk);
        $openingBalances = $this->openingBalances($organization, $periodStart, $clientIdChunk);
        $openingAdjustmentAmounts = $this->openingAdjustmentAmounts($billingPeriod, $clientIdChunk);
        $paidAmounts = $this->paidAmounts($billingPeriod, $clientIdChunk);
        $adjustmentAmounts = $this->adjustmentAmounts($billingPeriod, $clientIdChunk);

        $closedAt = now();
        $rows = [];

        foreach ($clients as $client) {
            if (isset($closedClientIds[$client->id])) {
                continue;
            }

            $calculation = $this->calculation($client, $utilityService, $periodStart, $tariffs, $metersByClient);

            if ($calculation instanceof BillingClosureIssue) {
                throw new RuntimeException("Данные абонента {$client->account_number} изменились во время закрытия месяца.");
            }

            $openingBalance = round(
                (float) ($openingBalances[$client->id] ?? 0)
                    + (float) ($openingAdjustmentAmounts[$client->id] ?? 0),
                2,
            );
            $paidAmount = (float) ($paidAmounts[$client->id] ?? 0);
            $adjustmentAmount = (float) ($adjustmentAmounts[$client->id] ?? 0);

            $rows[] = [
                'organization_id' => $organization->id,
                'client_id' => $client->id,
                'utility_service_id' => $utilityService?->id,
                'billing_period_id' => $billingPeriod->id,
                'account_number' => $client->account_number,
                'client_name' => $client->name,
                'utility_service_name' => $utilityService?->name,
                'billing_type' => $client->billing_type,
                'volume' => $calculation['volume'],
                'tariff_price' => $calculation['tariff_price'],
                'amount' => $calculation['amount'],
                'paid_amount' => $paidAmount,
                'adjustment_amount' => $adjustmentAmount,
                'opening_balance' => $openingBalance,
                'closing_balance' => round($openingBalance + $calculation['amount'] - $paidAmount + $adjustmentAmount, 2),
                'closed_at' => $closedAt,
                'created_at' => $closedAt,
                'updated_at' => $closedAt,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        Accrual::query()->insert($rows);

        return count($rows);
    }

    /**
     * @param  array<string, Tariff>  $tariffs
     * @param  array<int, EloquentCollection<int, Meter>>  $metersByClient
     * @return array{volume:float|null, tariff_price:float|null, amount:float}|BillingClosureIssue
     */
    private function calculation(
        Client $client,
        ?UtilityService $utilityService,
        CarbonImmutable $periodStart,
        array $tariffs,
        array $metersByClient,
    ): array|BillingClosureIssue {
        if (! $utilityService) {
            return new BillingClosureIssue('missing_organization_utility_service', 'Не задана услуга организации.');
        }

        return match ($client->billing_type) {
            'fixed' => $this->fixedCalculation($client),
            'meter' => $this->meterCalculation($client, $utilityService, $periodStart, $tariffs, $metersByClient),
            'per_person' => $this->perPersonCalculation($client, $utilityService, $periodStart, $tariffs),
            default => new BillingClosureIssue(
                'unsupported_billing_type',
                'Не выбран поддерживаемый тип начисления.',
                ['billing_type' => $client->billing_type],
            ),
        };
    }

    /**
     * @return array{volume:null, tariff_price:null, amount:float}|BillingClosureIssue
     */
    private function fixedCalculation(Client $client): array|BillingClosureIssue
    {
        if ((float) $client->fixed_amount <= 0) {
            return new BillingClosureIssue('missing_fixed_amount', 'Не указана фиксированная сумма.');
        }

        return [
            'volume' => null,
            'tariff_price' => null,
            'amount' => (float) $client->fixed_amount,
        ];
    }

    /**
     * @param  array<string, Tariff>  $tariffs
     * @return array{volume:float, tariff_price:float, amount:float}|BillingClosureIssue
     */
    private function perPersonCalculation(
        Client $client,
        UtilityService $utilityService,
        CarbonImmutable $periodStart,
        array $tariffs,
    ): array|BillingClosureIssue {
        if ((int) $client->residents_count <= 0) {
            return new BillingClosureIssue('missing_residents_count', 'Не указано количество проживающих.');
        }

        $tariff = $this->activeTariff($client, $tariffs);

        if (! $tariff) {
            return $this->missingTariffIssue($client, $utilityService, $periodStart);
        }

        if ((float) $tariff->per_person_price <= 0) {
            return new BillingClosureIssue(
                'missing_per_person_price',
                'Не указана цена тарифа на одного человека.',
                ['tariff_id' => $tariff->id],
            );
        }

        $volume = (float) $client->residents_count;
        $tariffPrice = (float) $tariff->per_person_price;

        return [
            'volume' => $volume,
            'tariff_price' => $tariffPrice,
            'amount' => round($volume * $tariffPrice, 2),
        ];
    }

    /**
     * @param  array<string, Tariff>  $tariffs
     * @param  array<int, EloquentCollection<int, Meter>>  $metersByClient
     * @return array{volume:float, tariff_price:float, amount:float}|BillingClosureIssue
     */
    private function meterCalculation(
        Client $client,
        UtilityService $utilityService,
        CarbonImmutable $periodStart,
        array $tariffs,
        array $metersByClient,
    ): array|BillingClosureIssue {
        $meters = $metersByClient[$client->id] ?? null;

        if (! $meters || $meters->isEmpty()) {
            return new BillingClosureIssue('missing_active_meters', 'Не найдены активные счётчики по услуге организации.');
        }

        $volume = 0.0;

        foreach ($meters as $meter) {
            $reading = $meter->readings->first();

            if (! $reading) {
                return new BillingClosureIssue(
                    'missing_meter_reading',
                    "Нет показания счётчика {$meter->number} за период.",
                    [
                        'meter_id' => $meter->id,
                        'meter_number' => $meter->number,
                    ],
                );
            }

            if ((float) $reading->consumption < 0) {
                return new BillingClosureIssue(
                    'negative_meter_consumption',
                    "Расход по счётчику {$meter->number} не может быть отрицательным.",
                    [
                        'meter_id' => $meter->id,
                        'meter_number' => $meter->number,
                        'consumption' => (float) $reading->consumption,
                    ],
                );
            }

            $volume += (float) $reading->consumption;
        }

        $tariff = $this->activeTariff($client, $tariffs);

        if (! $tariff) {
            return $this->missingTariffIssue($client, $utilityService, $periodStart);
        }

        if ((float) $tariff->unit_price <= 0) {
            return new BillingClosureIssue(
                'missing_unit_price',
                'Не указана цена за единицу услуги.',
                ['tariff_id' => $tariff->id],
            );
        }

        $tariffPrice = (float) $tariff->unit_price;

        return [
            'volume' => $volume,
            'tariff_price' => $tariffPrice,
            'amount' => round($volume * $tariffPrice, 2),
        ];
    }

    private function missingTariffIssue(Client $client, UtilityService $utilityService, CarbonImmutable $periodStart): BillingClosureIssue
    {
        return new BillingClosureIssue(
            'missing_tariff',
            'Не найден активный тариф на начало периода.',
            [
                'client_type' => $this->clientTypeValue($client),
                'utility_service_id' => $utilityService->id,
                'period_start' => $periodStart->toDateString(),
            ],
        );
    }

    /**
     * The newest active tariff of every client type, resolved with one query for the whole run.
     *
     * @return array<string, Tariff>
     */
    private function activeTariffsByClientType(Organization $organization, ?UtilityService $utilityService, CarbonImmutable $periodStart): array
    {
        if (! $utilityService) {
            return [];
        }

        $tariffs = [];

        $activeTariffs = Tariff::query()
            ->where('organization_id', $organization->id)
            ->where('utility_service_id', $utilityService->id)
            ->where('status', 'active')
            ->where('starts_on', '<=', $periodStart->toDateString())
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();

        foreach ($activeTariffs as $tariff) {
            $clientType = $this->clientTypeValue($tariff);

            if (! isset($tariffs[$clientType])) {
                $tariffs[$clientType] = $tariff;
            }
        }

        return $tariffs;
    }

    /**
     * @param  array<string, Tariff>  $tariffs
     */
    private function activeTariff(Client $client, array $tariffs): ?Tariff
    {
        return $tariffs[$this->clientTypeValue($client)] ?? null;
    }

    /**
     * @param  list<int>  $clientIds
     * @return EloquentCollection<int, Client>
     */
    private function clientChunk(array $clientIds): EloquentCollection
    {
        return Client::query()
            ->whereKey($clientIds)
            ->orderBy('id')
            ->get();
    }

    /**
     * Clients of the batch that already have an accrual in the billing period.
     *
     * @param  list<int>  $clientIds
     * @return array<int, int>
     */
    private function closedClientIds(BillingPeriod $billingPeriod, array $clientIds): array
    {
        return array_flip(
            Accrual::query()
                ->whereBelongsTo($billingPeriod)
                ->whereIn('client_id', $clientIds)
                ->pluck('client_id')
                ->all(),
        );
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, EloquentCollection<int, Meter>>
     */
    private function activeMetersByClient(
        Organization $organization,
        BillingPeriod $billingPeriod,
        ?UtilityService $utilityService,
        array $clientIds,
    ): array {
        if (! $utilityService) {
            return [];
        }

        return Meter::query()
            ->whereBelongsTo($organization)
            ->whereIn('client_id', $clientIds)
            ->where('status', 'active')
            ->where('utility_service_id', $utilityService->id)
            ->with(['readings' => fn ($query) => $query->whereBelongsTo($billingPeriod)])
            ->orderBy('id')
            ->get()
            ->groupBy('client_id')
            ->all();
    }

    /**
     * Closing balance of the newest accrual before the billing period, per client.
     *
     * @param  list<int>  $clientIds
     * @return array<int, string>
     */
    private function openingBalances(Organization $organization, CarbonImmutable $periodStart, array $clientIds): array
    {
        $rankedAccruals = DB::table('accruals')
            ->join('billing_periods', 'billing_periods.id', '=', 'accruals.billing_period_id')
            ->select([
                'accruals.client_id',
                'accruals.closing_balance',
                DB::raw('row_number() over (partition by accruals.client_id order by billing_periods.starts_on desc, accruals.id desc) as closing_balance_rank'),
            ])
            ->where('accruals.organization_id', $organization->id)
            ->whereIn('accruals.client_id', $clientIds)
            ->where('billing_periods.starts_on', '<', $periodStart->toDateString());

        return DB::query()
            ->fromSub($rankedAccruals, 'ranked_accruals')
            ->where('closing_balance_rank', 1)
            ->pluck('closing_balance', 'client_id')
            ->all();
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, string>
     */
    private function paidAmounts(BillingPeriod $billingPeriod, array $clientIds): array
    {
        return Payment::query()
            ->whereBelongsTo($billingPeriod)
            ->whereIn('client_id', $clientIds)
            ->groupBy('client_id')
            ->selectRaw('client_id, sum(amount) as period_amount')
            ->pluck('period_amount', 'client_id')
            ->all();
    }

    /**
     * Incoming balances of the period, per client.
     *
     * Opening balance adjustments enter the opening balance of the accrual
     * instead of its turnover, so they are summed apart from the other types.
     *
     * @param  list<int>  $clientIds
     * @return array<int, string>
     */
    private function openingAdjustmentAmounts(BillingPeriod $billingPeriod, array $clientIds): array
    {
        return BalanceAdjustment::query()
            ->whereBelongsTo($billingPeriod)
            ->whereIn('client_id', $clientIds)
            ->where('type', BalanceAdjustmentType::OpeningBalance->value)
            ->groupBy('client_id')
            ->selectRaw('client_id, sum(amount) as period_amount')
            ->pluck('period_amount', 'client_id')
            ->all();
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, string>
     */
    private function adjustmentAmounts(BillingPeriod $billingPeriod, array $clientIds): array
    {
        return BalanceAdjustment::query()
            ->whereBelongsTo($billingPeriod)
            ->whereIn('client_id', $clientIds)
            ->where('type', '!=', BalanceAdjustmentType::OpeningBalance->value)
            ->groupBy('client_id')
            ->selectRaw('client_id, sum(amount) as period_amount')
            ->pluck('period_amount', 'client_id')
            ->all();
    }

    private function clientTypeValue(Client|Tariff $record): string
    {
        $clientType = $record->client_type;

        if ($clientType instanceof ClientType) {
            return $clientType->value;
        }

        return (string) $clientType;
    }

    private function lockedBillingPeriod(Organization $organization, string|BillingPeriod $period, ?User $openedBy): BillingPeriod
    {
        if (is_string($period)) {
            $period = BillingPeriod::openFor($organization, $period, $openedBy);
        }

        return BillingPeriod::query()
            ->whereBelongsTo($organization)
            ->whereKey($period->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ensureCanClose(BillingPeriod $billingPeriod): void
    {
        if ($billingPeriod->canStartClosing()) {
            return;
        }

        if ($billingPeriod->status === BillingPeriodStatus::Processing) {
            throw new InvalidArgumentException('Расчётный месяц уже закрывается.');
        }

        throw new InvalidArgumentException('Расчётный месяц уже закрыт.');
    }

    private function actingUser(?User $user): ?User
    {
        if ($user instanceof User) {
            return $user;
        }

        $authenticatedUser = auth()->user();

        return $authenticatedUser instanceof User ? $authenticatedUser : null;
    }

    /**
     * @return array{active:int, created:int, skipped:int, failed:int, errors:list<array{client_id:int, account_number:string, client_name:string, message:string}>}
     */
    private function emptySummary(): array
    {
        return [
            'active' => 0,
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];
    }

    /**
     * @param  array{active:int, created:int, skipped:int, failed:int, errors:list<array{client_id:int, account_number:string, client_name:string, message:string}>}  $summary
     * @param  list<array{client_id:int|null, account_number:string|null, client_name:string|null, billing_type:string|null, code:string, message:string, context:array<string, mixed>|null}>  $closureErrors
     */
    private function recordError(array &$summary, array &$closureErrors, Client $client, BillingClosureIssue $issue): void
    {
        $summary['failed']++;
        $summary['errors'][] = [
            'client_id' => $client->id,
            'account_number' => $client->account_number,
            'client_name' => $client->name,
            'message' => $issue->message,
        ];

        $closureErrors[] = [
            'client_id' => $client->id,
            'account_number' => $client->account_number,
            'client_name' => $client->name,
            'billing_type' => $client->billing_type,
            'code' => $issue->code,
            'message' => $issue->message,
            'context' => $issue->context === [] ? null : $issue->context,
        ];
    }
}
