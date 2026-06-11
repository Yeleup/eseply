<?php

namespace App\Models;

use App\BillingPeriodStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

trait HasBillingPeriod
{
    private ?string $pendingBillingPeriodCode = null;

    public function billingPeriod(): BelongsTo
    {
        return $this->belongsTo(BillingPeriod::class);
    }

    public function getPeriodAttribute(): ?string
    {
        if ($this->relationLoaded('billingPeriod')) {
            return $this->billingPeriod?->code;
        }

        if (! $this->billing_period_id) {
            return $this->pendingBillingPeriodCode;
        }

        return $this->billingPeriod?->code;
    }

    public function setPeriodAttribute(mixed $period): void
    {
        if ($period === null || $period === '') {
            return;
        }

        $this->pendingBillingPeriodCode = BillingPeriod::normalizeCode((string) $period);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForPeriod(Builder $query, string $period): Builder
    {
        return $query->whereHas(
            'billingPeriod',
            fn (Builder $query): Builder => $query->whereDate('starts_on', BillingPeriod::periodStart($period)->toDateString()),
        );
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBeforePeriod(Builder $query, string $period): Builder
    {
        return $query->whereHas(
            'billingPeriod',
            fn (Builder $query): Builder => $query->whereDate('starts_on', '<', BillingPeriod::periodStart($period)->toDateString()),
        );
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrderByBillingPeriodDesc(Builder $query): Builder
    {
        return $query->orderByDesc(
            BillingPeriod::query()
                ->select('starts_on')
                ->whereColumn('billing_periods.id', $query->getModel()->qualifyColumn('billing_period_id')),
        );
    }

    protected function resolveBillingPeriodIdFromPeriodCode(
        BillingPeriodStatus $newBillingPeriodStatus = BillingPeriodStatus::Open,
        bool $useCurrentWhenMissing = false,
    ): void {
        if ($this->pendingBillingPeriodCode === null) {
            if ($useCurrentWhenMissing && ! $this->getAttribute('billing_period_id')) {
                $this->setCurrentBillingPeriodId();
            }

            $this->assertBillingPeriodBelongsToOrganization();

            return;
        }

        $organizationId = $this->getAttribute('organization_id');

        if (! $organizationId) {
            throw new InvalidArgumentException('Нельзя определить организацию для расчётного месяца.');
        }

        $billingPeriod = BillingPeriod::openFor(
            organization: (int) $organizationId,
            period: $this->pendingBillingPeriodCode,
            status: $newBillingPeriodStatus,
        );

        $this->setAttribute('billing_period_id', $billingPeriod->getKey());
        $this->pendingBillingPeriodCode = null;

        $this->assertBillingPeriodBelongsToOrganization();
    }

    private function setCurrentBillingPeriodId(): void
    {
        $organizationId = $this->getAttribute('organization_id');

        if (! $organizationId) {
            throw new InvalidArgumentException('Нельзя определить организацию для расчётного месяца.');
        }

        $this->setAttribute(
            'billing_period_id',
            BillingPeriod::requireCurrentEditableFor((int) $organizationId)->getKey(),
        );
    }

    protected function assertBillingPeriodBelongsToOrganization(): void
    {
        $billingPeriodId = $this->getAttribute('billing_period_id');
        $organizationId = $this->getAttribute('organization_id');

        if (! $billingPeriodId || ! $organizationId) {
            return;
        }

        $belongsToOrganization = BillingPeriod::query()
            ->whereKey($billingPeriodId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (! $belongsToOrganization) {
            throw ValidationException::withMessages([
                'billing_period_id' => 'Расчётный месяц должен принадлежать выбранной организации.',
            ]);
        }
    }

    protected function ensureBillingPeriodIsEditable(): void
    {
        $billingPeriodId = $this->getAttribute('billing_period_id');

        if (! $billingPeriodId) {
            return;
        }

        $billingPeriod = BillingPeriod::query()->find($billingPeriodId);

        if ($billingPeriod?->isEditable() === false) {
            throw ValidationException::withMessages([
                'billing_period_id' => 'Закрытый расчётный месяц нельзя изменять.',
            ]);
        }
    }
}
