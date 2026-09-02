<?php

namespace App\Console\Commands;

use App\Models\BillingPeriod;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\Receipt;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Rebuilds the receipts of meter readings that were written without the model.
 *
 * A receipt is created by the `saved` hook of `MeterReading`, so a bulk `INSERT`
 * of readings leaves the organization without receipts: the dashboard shows no
 * preliminary charge and the turnover sheet reads the period as empty. The whole
 * calculation stays in `Receipt::fromMeterReading()`, so this command only replays
 * it over readings that are already stored.
 *
 * A receipt covers one subscriber and one billing period, and it sums the readings
 * of every meter of that subscriber, so each pair is rebuilt once even when the
 * subscriber has several meters.
 */
#[Signature('receipts:rebuild
            {--organization= : Идентификатор организации; по умолчанию все организации}
            {--period= : Код расчётного месяца в формате ГГГГММ, например 202608; по умолчанию все месяцы}
            {--chunk=500 : Сколько показаний читать за один проход}')]
#[Description('Пересобрать квитанции по сохранённым показаниям счётчиков')]
class RebuildReceiptsCommand extends Command
{
    public function handle(): int
    {
        $organization = $this->organization();

        if ($organization === false) {
            return self::FAILURE;
        }

        $billingPeriodIds = $this->billingPeriodIds($organization);

        if ($billingPeriodIds === false) {
            return self::FAILURE;
        }

        $chunkSize = max(1, (int) $this->option('chunk'));
        $readingsCount = $this->readings($organization, $billingPeriodIds)->count();

        if ($readingsCount === 0) {
            $this->components->warn('Показаний по заданным условиям нет, пересобирать нечего.');

            return self::SUCCESS;
        }

        $this->components->info("Показаний: {$readingsCount}. Пересобираю квитанции.");

        $rebuiltReceipts = $this->rebuild($organization, $billingPeriodIds, $chunkSize, $readingsCount);

        $this->newLine(2);
        $this->components->info("Квитанций пересобрано: {$rebuiltReceipts}.");

        return self::SUCCESS;
    }

    /**
     * The organization of the run, `null` for every organization, `false` when the
     * given identifier does not exist.
     */
    private function organization(): Organization|null|false
    {
        $organizationId = $this->option('organization');

        if ($organizationId === null) {
            return null;
        }

        $organization = Organization::query()->find($organizationId);

        if (! $organization instanceof Organization) {
            $this->components->error("Организация {$organizationId} не найдена.");

            return false;
        }

        return $organization;
    }

    /**
     * The billing periods of the run, `null` for every period, `false` when the
     * given code is malformed or matches no period.
     *
     * @return list<int>|null|false
     */
    private function billingPeriodIds(?Organization $organization): array|null|false
    {
        $period = $this->option('period');

        if ($period === null) {
            return null;
        }

        try {
            $periodStart = BillingPeriod::periodStart((string) $period);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return false;
        }

        /** @var list<int> $billingPeriodIds */
        $billingPeriodIds = BillingPeriod::query()
            ->when(
                $organization instanceof Organization,
                fn (Builder $query): Builder => $query->forOrganization($organization),
            )
            ->whereDate('starts_on', $periodStart->toDateString())
            ->pluck('id')
            ->all();

        if ($billingPeriodIds === []) {
            $this->components->error("Расчётный месяц {$period} не найден.");

            return false;
        }

        return $billingPeriodIds;
    }

    /**
     * @param  list<int>|null  $billingPeriodIds
     * @return Builder<MeterReading>
     */
    private function readings(?Organization $organization, ?array $billingPeriodIds): Builder
    {
        return MeterReading::query()
            ->when(
                $organization instanceof Organization,
                fn (Builder $query): Builder => $query->whereBelongsTo($organization),
            )
            ->when(
                $billingPeriodIds !== null,
                fn (Builder $query): Builder => $query->whereIn('billing_period_id', $billingPeriodIds),
            );
    }

    /**
     * @param  list<int>|null  $billingPeriodIds
     */
    private function rebuild(?Organization $organization, ?array $billingPeriodIds, int $chunkSize, int $readingsCount): int
    {
        /**
         * Every pair of subscriber and billing period already rebuilt in this run.
         *
         * @var array<string, true> $rebuiltPairs
         */
        $rebuiltPairs = [];

        $progressBar = $this->output->createProgressBar($readingsCount);
        $progressBar->start();

        /**
         * The relations `Receipt::fromMeterReading()` needs are loaded per chunk,
         * so its own `loadMissing()` does not query once per reading.
         */
        $this->readings($organization, $billingPeriodIds)
            ->with(['billingPeriod', 'client', 'organization.utilityService', 'utilityService'])
            ->chunkById($chunkSize, function (Collection $readings) use (&$rebuiltPairs, $progressBar): void {
                foreach ($readings as $reading) {
                    $progressBar->advance();

                    $pair = $reading->client_id.':'.$reading->billing_period_id;

                    if (isset($rebuiltPairs[$pair])) {
                        continue;
                    }

                    $rebuiltPairs[$pair] = true;

                    Receipt::fromMeterReading($reading);
                }
            });

        $progressBar->finish();

        return count($rebuiltPairs);
    }
}
