<?php

namespace App\Reports\Contracts;

use App\Models\BillingPeriod;
use App\Models\Organization;

/**
 * A report built for one billing period chosen by the operator.
 *
 * Reports that do not implement this contract always use the current
 * open or failed billing period of the organization.
 */
interface SelectsBillingPeriod
{
    /**
     * Billing period used while the operator has not chosen one.
     */
    public function defaultBillingPeriodFor(Organization $organization): ?BillingPeriod;

    /**
     * A copy of the report bound to the given billing period.
     */
    public function forBillingPeriod(?BillingPeriod $billingPeriod): static;
}
