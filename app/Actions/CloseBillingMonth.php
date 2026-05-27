<?php

namespace App\Actions;

use App\Models\Accrual;
use App\Models\Client;
use App\Models\Normative;
use App\Models\Organization;
use App\Models\Receipt;
use App\Models\Tariff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CloseBillingMonth
{
    /**
     * @return array{active:int, created:int, skipped:int, failed:int, errors:list<array{client_id:int, account_number:string, client_name:string, message:string}>}
     */
    public function handle(Organization $organization, string $period): array
    {
        $periodStart = $this->periodStart($period);

        return DB::transaction(function () use ($organization, $period, $periodStart): array {
            $summary = [
                'active' => 0,
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
                'errors' => [],
            ];

            $clients = $organization->clients()
                ->with([
                    'tariffCategory',
                    'utilityService',
                ])
                ->where('status', 'active')
                ->orderBy('id')
                ->get();

            $summary['active'] = $clients->count();

            foreach ($clients as $client) {
                $existingAccrual = $this->existingAccrual($organization, $client, $period);

                if ($existingAccrual) {
                    Receipt::fromAccrual($existingAccrual);

                    $summary['skipped']++;

                    continue;
                }

                $calculation = $this->calculation($client, $period, $periodStart);

                if (is_string($calculation)) {
                    $this->recordError($summary, $client, $calculation);

                    continue;
                }

                $openingBalance = $this->openingBalance($client, $period);
                $paidAmount = $this->paidAmount($client, $period);
                $closingBalance = $openingBalance + $calculation['amount'] - $paidAmount;

                $accrual = Accrual::create([
                    'organization_id' => $organization->id,
                    'client_id' => $client->id,
                    'utility_service_id' => $client->utility_service_id,
                    'period' => $period,
                    'account_number' => $client->account_number,
                    'client_name' => $client->name,
                    'utility_service_name' => $client->utilityService?->name,
                    'billing_type' => $client->billing_type,
                    'volume' => $calculation['volume'],
                    'tariff_price' => $calculation['tariff_price'],
                    'amount' => $calculation['amount'],
                    'paid_amount' => $paidAmount,
                    'opening_balance' => $openingBalance,
                    'closing_balance' => $closingBalance,
                    'closed_at' => now(),
                ]);

                Receipt::fromAccrual($accrual);

                $summary['created']++;
            }

            return $summary;
        });
    }

    /**
     * @return array{volume:float|null, tariff_price:float|null, amount:float}|string
     */
    private function calculation(Client $client, string $period, CarbonImmutable $periodStart): array|string
    {
        if (! $client->utility_service_id) {
            return 'Не выбрана услуга клиента.';
        }

        return match ($client->billing_type) {
            'fixed' => $this->fixedCalculation($client),
            'meter' => $this->meterCalculation($client, $period, $periodStart),
            'normative' => $this->normativeCalculation($client, $periodStart),
            default => 'Не выбран поддерживаемый тип начисления.',
        };
    }

    private function existingAccrual(Organization $organization, Client $client, string $period): ?Accrual
    {
        return Accrual::query()
            ->whereBelongsTo($organization)
            ->whereBelongsTo($client)
            ->where('period', $period)
            ->first();
    }

    /**
     * @return array{volume:null, tariff_price:null, amount:float}|string
     */
    private function fixedCalculation(Client $client): array|string
    {
        if ((float) $client->fixed_amount <= 0) {
            return 'Не указана фиксированная сумма.';
        }

        return [
            'volume' => null,
            'tariff_price' => null,
            'amount' => (float) $client->fixed_amount,
        ];
    }

    /**
     * @return array{volume:float, tariff_price:float, amount:float}|string
     */
    private function normativeCalculation(Client $client, CarbonImmutable $periodStart): array|string
    {
        if (! $client->tariff_category_id) {
            return 'Не выбрана категория тарифа.';
        }

        $normative = $this->activeNormative($client, $periodStart);
        $tariff = $this->activeTariff($client, $periodStart);

        if (! $tariff) {
            return 'Не найден активный тариф на начало периода.';
        }

        if (! $normative) {
            return 'Не найден активный норматив на начало периода.';
        }

        $volume = match ($normative->calculation_type) {
            'per_person' => $this->perPersonVolume($client, (float) $normative->value),
            'per_area' => $this->perAreaVolume($client, (float) $normative->value),
            'per_object' => (float) $normative->value,
            default => null,
        };

        if ($volume === null) {
            return 'Неверный тип расчёта норматива.';
        }

        if (is_string($volume)) {
            return $volume;
        }

        $tariffPrice = (float) $tariff->price;

        return [
            'volume' => $volume,
            'tariff_price' => $tariffPrice,
            'amount' => round($volume * $tariffPrice, 2),
        ];
    }

    /**
     * @return array{volume:float, tariff_price:float, amount:float}|string
     */
    private function meterCalculation(Client $client, string $period, CarbonImmutable $periodStart): array|string
    {
        if (! $client->tariff_category_id) {
            return 'Не выбрана категория тарифа.';
        }

        $meter = $client->meters()
            ->where('status', 'active')
            ->where('utility_service_id', $client->utility_service_id)
            ->first();

        if (! $meter) {
            return 'Не найден активный счётчик по услуге клиента.';
        }

        $reading = $meter->readings()
            ->where('period', $period)
            ->first();

        if (! $reading) {
            return 'Нет показания счётчика за период.';
        }

        if ((float) $reading->consumption < 0) {
            return 'Расход по счётчику не может быть отрицательным.';
        }

        $tariff = $this->activeTariff($client, $periodStart);

        if (! $tariff) {
            return 'Не найден активный тариф на начало периода.';
        }

        $volume = (float) $reading->consumption;
        $tariffPrice = (float) $tariff->price;

        return [
            'volume' => $volume,
            'tariff_price' => $tariffPrice,
            'amount' => round($volume * $tariffPrice, 2),
        ];
    }

    private function perPersonVolume(Client $client, float $normative): float|string
    {
        if ((int) $client->residents_count <= 0) {
            return 'Не указано количество проживающих.';
        }

        return (int) $client->residents_count * $normative;
    }

    private function perAreaVolume(Client $client, float $normative): float|string
    {
        if ((float) $client->area <= 0) {
            return 'Не указана площадь.';
        }

        return (float) $client->area * $normative;
    }

    private function activeNormative(Client $client, CarbonImmutable $periodStart): ?Normative
    {
        return Normative::query()
            ->where('organization_id', $client->organization_id)
            ->where('utility_service_id', $client->utility_service_id)
            ->where('tariff_category_id', $client->tariff_category_id)
            ->where('status', 'active')
            ->whereDate('starts_on', '<=', $periodStart->toDateString())
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();
    }

    private function activeTariff(Client $client, CarbonImmutable $periodStart): ?Tariff
    {
        return Tariff::query()
            ->where('organization_id', $client->organization_id)
            ->where('utility_service_id', $client->utility_service_id)
            ->where('tariff_category_id', $client->tariff_category_id)
            ->where('status', 'active')
            ->whereDate('starts_on', '<=', $periodStart->toDateString())
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();
    }

    private function openingBalance(Client $client, string $period): float
    {
        $previousAccrual = $client->accruals()
            ->where('period', '<', $period)
            ->orderByDesc('period')
            ->first();

        if ($previousAccrual) {
            return (float) $previousAccrual->closing_balance;
        }

        return (float) $client->starting_balance;
    }

    private function paidAmount(Client $client, string $period): float
    {
        return (float) $client->payments()
            ->where('period', $period)
            ->sum('amount');
    }

    private function periodStart(string $period): CarbonImmutable
    {
        if (! preg_match('/^\d{6}$/', $period)) {
            throw new InvalidArgumentException('Период должен быть в формате ГГГГММ.');
        }

        $month = (int) substr($period, 4, 2);

        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('Месяц в периоде должен быть от 01 до 12.');
        }

        return CarbonImmutable::createFromFormat('Ym', $period)->startOfMonth();
    }

    /**
     * @param  array{active:int, created:int, skipped:int, failed:int, errors:list<array{client_id:int, account_number:string, client_name:string, message:string}>}  $summary
     */
    private function recordError(array &$summary, Client $client, string $message): void
    {
        $summary['failed']++;
        $summary['errors'][] = [
            'client_id' => $client->id,
            'account_number' => $client->account_number,
            'client_name' => $client->name,
            'message' => $message,
        ];
    }
}
