<?php

namespace App\Actions;

use App\BillingPeriodStatus;
use App\ClientType;
use App\Models\Accrual;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Tariff;
use App\Models\UtilityService;
use App\Support\BillingClosureIssue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CloseBillingMonth
{
    /**
     * @return array{active:int, created:int, skipped:int, failed:int, errors:list<array{client_id:int, account_number:string, client_name:string, message:string}>}
     */
    public function handle(Organization $organization, string|BillingPeriod $period): array
    {
        return DB::transaction(function () use ($organization, $period): array {
            $billingPeriod = $this->lockedBillingPeriod($organization, $period);
            $periodStart = CarbonImmutable::instance($billingPeriod->starts_on)->startOfMonth();

            $this->ensureCanClose($billingPeriod);

            $billingPeriod->markProcessing();
            $organization->loadMissing('utilityService');

            $summary = [
                'active' => 0,
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
                'errors' => [],
            ];

            $clients = $organization->clients()
                ->where('status', 'active')
                ->orderBy('id')
                ->get();

            $summary['active'] = $clients->count();

            $pendingAccruals = [];
            $closureErrors = [];

            foreach ($clients as $client) {
                $existingAccrual = $this->existingAccrual($organization, $client, $billingPeriod);

                if ($existingAccrual) {
                    $summary['skipped']++;

                    continue;
                }

                $calculation = $this->calculation($client, $organization->utilityService, $billingPeriod, $periodStart);

                if ($calculation instanceof BillingClosureIssue) {
                    $this->recordError($summary, $closureErrors, $client, $calculation);

                    continue;
                }

                $pendingAccruals[] = [
                    'client' => $client,
                    'calculation' => $calculation,
                ];
            }

            if ($summary['failed'] > 0) {
                $billingPeriod->markFailed($summary, 'Не все активные абоненты были рассчитаны.', $closureErrors);

                return $summary;
            }

            foreach ($pendingAccruals as $pendingAccrual) {
                /** @var Client $client */
                $client = $pendingAccrual['client'];
                /** @var array{volume:float|null, tariff_price:float|null, amount:float} $calculation */
                $calculation = $pendingAccrual['calculation'];

                $openingBalance = $this->openingBalance($client, $billingPeriod);
                $paidAmount = $this->paidAmount($client, $billingPeriod);
                $adjustmentAmount = $this->adjustmentAmount($client, $billingPeriod);
                $closingBalance = $openingBalance + $calculation['amount'] - $paidAmount + $adjustmentAmount;

                Accrual::create([
                    'organization_id' => $organization->id,
                    'client_id' => $client->id,
                    'utility_service_id' => $organization->utilityService?->id,
                    'billing_period_id' => $billingPeriod->id,
                    'account_number' => $client->account_number,
                    'client_name' => $client->name,
                    'utility_service_name' => $organization->utilityService?->name,
                    'billing_type' => $client->billing_type,
                    'volume' => $calculation['volume'],
                    'tariff_price' => $calculation['tariff_price'],
                    'amount' => $calculation['amount'],
                    'paid_amount' => $paidAmount,
                    'adjustment_amount' => $adjustmentAmount,
                    'opening_balance' => $openingBalance,
                    'closing_balance' => $closingBalance,
                    'closed_at' => now(),
                ]);

                $summary['created']++;
            }

            $billingPeriod->markClosed($summary, auth()->user());

            return $summary;
        }, attempts: 5);
    }

    /**
     * @return array{volume:float|null, tariff_price:float|null, amount:float}|BillingClosureIssue
     */
    private function calculation(Client $client, ?UtilityService $utilityService, BillingPeriod $billingPeriod, CarbonImmutable $periodStart): array|BillingClosureIssue
    {
        if (! $utilityService) {
            return new BillingClosureIssue('missing_organization_utility_service', 'Не задана услуга организации.');
        }

        return match ($client->billing_type) {
            'fixed' => $this->fixedCalculation($client),
            'meter' => $this->meterCalculation($client, $utilityService, $billingPeriod, $periodStart),
            'per_person' => $this->perPersonCalculation($client, $utilityService, $periodStart),
            default => new BillingClosureIssue(
                'unsupported_billing_type',
                'Не выбран поддерживаемый тип начисления.',
                ['billing_type' => $client->billing_type],
            ),
        };
    }

    private function existingAccrual(Organization $organization, Client $client, BillingPeriod $billingPeriod): ?Accrual
    {
        return Accrual::query()
            ->whereBelongsTo($organization)
            ->whereBelongsTo($client)
            ->whereBelongsTo($billingPeriod)
            ->first();
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
     * @return array{volume:float, tariff_price:float, amount:float}|BillingClosureIssue
     */
    private function perPersonCalculation(Client $client, UtilityService $utilityService, CarbonImmutable $periodStart): array|BillingClosureIssue
    {
        if ((int) $client->residents_count <= 0) {
            return new BillingClosureIssue('missing_residents_count', 'Не указано количество проживающих.');
        }

        $tariff = $this->activeTariff($client, $utilityService, $periodStart);

        if (! $tariff) {
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
     * @return array{volume:float, tariff_price:float, amount:float}|BillingClosureIssue
     */
    private function meterCalculation(Client $client, UtilityService $utilityService, BillingPeriod $billingPeriod, CarbonImmutable $periodStart): array|BillingClosureIssue
    {
        $meters = $client->meters()
            ->with([
                'readings' => fn ($query) => $query->whereBelongsTo($billingPeriod),
            ])
            ->where('status', 'active')
            ->where('utility_service_id', $utilityService->id)
            ->orderBy('id')
            ->get();

        if ($meters->isEmpty()) {
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

        $tariff = $this->activeTariff($client, $utilityService, $periodStart);

        if (! $tariff) {
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

    private function activeTariff(Client $client, UtilityService $utilityService, CarbonImmutable $periodStart): ?Tariff
    {
        return Tariff::query()
            ->where('organization_id', $client->organization_id)
            ->where('utility_service_id', $utilityService->id)
            ->where('client_type', $this->clientTypeValue($client))
            ->where('status', 'active')
            ->whereDate('starts_on', '<=', $periodStart->toDateString())
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();
    }

    private function clientTypeValue(Client $client): string
    {
        if ($client->client_type instanceof ClientType) {
            return $client->client_type->value;
        }

        return (string) $client->client_type;
    }

    private function openingBalance(Client $client, BillingPeriod $billingPeriod): float
    {
        $previousAccrual = $client->accruals()
            ->beforePeriod($billingPeriod->code)
            ->orderByBillingPeriodDesc()
            ->first();

        if ($previousAccrual) {
            return (float) $previousAccrual->closing_balance;
        }

        return 0.0;
    }

    private function paidAmount(Client $client, BillingPeriod $billingPeriod): float
    {
        return (float) $client->payments()
            ->whereBelongsTo($billingPeriod)
            ->sum('amount');
    }

    private function adjustmentAmount(Client $client, BillingPeriod $billingPeriod): float
    {
        return (float) $client->balanceAdjustments()
            ->whereBelongsTo($billingPeriod)
            ->sum('amount');
    }

    private function lockedBillingPeriod(Organization $organization, string|BillingPeriod $period): BillingPeriod
    {
        if (is_string($period)) {
            $period = BillingPeriod::openFor($organization, $period, auth()->user());
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
