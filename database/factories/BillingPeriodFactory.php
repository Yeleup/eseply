<?php

namespace Database\Factories;

use App\BillingPeriodStatus;
use App\Models\BillingPeriod;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingPeriod>
 */
class BillingPeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'starts_on' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-01'),
            'status' => BillingPeriodStatus::Open,
            'opened_at' => now(),
        ];
    }

    public function period(string $period): self
    {
        return $this->state(fn (): array => [
            'starts_on' => BillingPeriod::periodStart($period)->toDateString(),
        ]);
    }

    public function closed(): self
    {
        return $this->state(fn (): array => [
            'status' => BillingPeriodStatus::Closed,
            'closed_at' => now(),
        ]);
    }
}
