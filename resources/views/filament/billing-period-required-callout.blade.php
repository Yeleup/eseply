@php
    use App\Filament\Support\CurrentBillingPeriod;
    use App\Models\Organization;
    use Filament\Support\Icons\Heroicon;
@endphp

@if ($tenant instanceof Organization && $billingPeriod === null)
    @once
        <style>
            .billing-period-required-callout {
                margin-top: 1rem;
            }
        </style>
    @endonce

    @if ($closingBillingPeriod !== null)
        <x-filament::callout
            color="info"
            :icon="Heroicon::OutlinedArrowPath"
            :heading="CurrentBillingPeriod::ClosingTitle"
            :description="CurrentBillingPeriod::ClosingDescription"
            class="billing-period-required-callout"
        />
    @else
        <x-filament::callout
            color="danger"
            :icon="Heroicon::OutlinedExclamationTriangle"
            :heading="CurrentBillingPeriod::MissingTitle"
            :description="CurrentBillingPeriod::MissingDescription"
            class="billing-period-required-callout"
        />
    @endif
@endif
