<?php

namespace App\Actions;

use App\Models\BillingPeriod;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptBillingClosureMeterReadings
{
    public const string MISSING = 'missing_meter_reading';

    public const string NEGATIVE = 'negative_meter_consumption';

    public function handle(Organization $organization, BillingPeriod $period, User $operator, string $code, ?int $meterId = null): int
    {
        abort_unless($operator->canManageOrganization($organization), 403);
        abort_unless(in_array($code, [self::MISSING, self::NEGATIVE], true), 422);

        return DB::transaction(function () use ($organization, $period, $operator, $code, $meterId): int {
            $period = BillingPeriod::query()->whereBelongsTo($organization)->lockForUpdate()->findOrFail($period->id);

            if (! $period->isEditable()) {
                throw ValidationException::withMessages(['billing_period_id' => 'Этот расчётный месяц уже закрыт или закрывается.']);
            }

            $meters = Meter::query()
                ->whereBelongsTo($organization)
                ->where('utility_service_id', $organization->utilityService?->id)
                ->where('status', 'active')
                ->whereHas('client', fn (Builder $query): Builder => $query
                    ->whereBelongsTo($organization)->where('status', 'active')->where('billing_type', 'meter'))
                ->when($meterId !== null, fn (Builder $query): Builder => $query->whereKey($meterId));

            if ($code === self::MISSING) {
                $meters->whereDoesntHave('readings', fn (Builder $query): Builder => $query->whereBelongsTo($period)->taken());
            } else {
                $meters->whereHas('readings', fn (Builder $query): Builder => $query->whereBelongsTo($period)->where('consumption', '<', 0));
            }

            $accepted = 0;

            foreach ($meters->lazyById(100) as $meter) {
                $reading = $meter->readings()->whereBelongsTo($period)->lockForUpdate()->first();

                if ($code === self::MISSING) {
                    if ($reading?->isTaken()) {
                        continue;
                    }

                    $previousReading = MeterReading::previousReadingForBillingPeriod($meter->id, $period->id) ?? 0;
                    $reading ??= $meter->readings()->make(['billing_period_id' => $period->id]);
                    $reading->fill(['previous_reading' => $previousReading, 'current_reading' => $previousReading]);
                    $reading->save();
                } elseif (! $reading || $reading->consumption >= 0 || $reading->hasAcceptedNegativeConsumption()) {
                    continue;
                }

                $reading->forceFill([
                    'closure_resolution' => $code,
                    'closure_accepted_by_user_id' => $operator->id,
                    'closure_accepted_at' => now(),
                ])->save();

                $period->closureErrors()
                    ->where('organization_id', $organization->id)
                    ->where('code', $code)
                    ->where('context->meter_id', $meter->id)
                    ->delete();
                $accepted++;
            }

            return $accepted;
        }, attempts: 5);
    }
}
