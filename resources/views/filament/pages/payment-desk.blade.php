@php
    $totals = $this->todayTotals();
    $results = $this->searchResults();
@endphp

<x-filament-panels::page>
    {{-- The modal's focus trap hands the focus back as it closes, so the search takes it on a later tick. --}}
    <div
        x-data="{
            focusSearch() {
                setTimeout(() => setTimeout(() => document.getElementById('payment-desk-search')?.focus()))
            },
        }"
        x-on:payment-desk-reset.window="focusSearch()"
        x-on:modal-closed.window="if ($event.target.closest?.('.fi-modal')?.querySelector('[data-payment-desk-pay]')) focusSearch()"
        class="fi-payment-desk flex flex-col gap-6"
    >
        <x-filament::section
            icon="heroicon-o-magnifying-glass"
            icon-color="primary"
            heading="Поиск абонента"
            :description="$this->hasBillingPeriod()
                ? 'Расчётный месяц: ' . $this->billingPeriodLabel() . '. Лицевой счёт, фамилия или телефон.'
                : 'Лицевой счёт, фамилия или телефон.'"
        >
            <input
                id="payment-desk-search"
                type="search"
                autofocus
                autocomplete="off"
                wire:model.live.debounce.300ms="search"
                placeholder="Лицевой счёт, фамилия или телефон"
                aria-label="Поиск абонента"
                class="fi-input w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-base text-gray-950 shadow-sm outline-none transition focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-white/20 dark:bg-white/5 dark:text-white"
            >

            @if ($this->isSearchTooShort())
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                    Введите минимум 2 символа: лицевой счёт, фамилию или телефон.
                </p>
            @elseif (filled(trim($this->search)) && $results->isEmpty())
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                    Ничего не найдено. Проверьте лицевой счёт или попробуйте фамилию.
                </p>
            @endif

            @if ($results->isNotEmpty())
                <ul class="mt-3 divide-y divide-gray-200 overflow-hidden rounded-xl border border-gray-200 dark:divide-white/10 dark:border-white/10">
                    @foreach ($results as $result)
                        @php $closing = $this->closingBalanceOf($result); @endphp
                        <li
                            wire:key="payment-desk-client-{{ $result->getKey() }}"
                            class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-950 dark:text-white">
                                    {{ $result->account_number }} — {{ $result->name }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $this->addressOf($result) }}
                                    @if (filled($result->phone)) · {{ $result->phone }} @endif
                                </p>
                            </div>

                            <div class="flex shrink-0 flex-wrap items-center gap-3">
                                @if ($closing > 0)
                                    <x-filament::badge color="danger">Долг {{ $this->money($closing) }}</x-filament::badge>
                                @elseif ($closing < 0)
                                    <x-filament::badge color="info">Переплата {{ $this->money(abs($closing)) }}</x-filament::badge>
                                @else
                                    <x-filament::badge color="success">Долга нет</x-filament::badge>
                                @endif

                                @if ($this->offersPayment($result))
                                    {{ ($this->payAction)(['client' => $result->getKey()]) }}
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>

                @unless ($this->hasBillingPeriod())
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                        Расчётный месяц не открыт: приём оплат недоступен.
                    </p>
                @endunless
            @endif
        </x-filament::section>

        @if ($totals['count'] > 0)
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Оплат за сегодня</div>
                    <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ number_format($totals['count'], 0, ',', ' ') }}</div>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/50 dark:bg-emerald-950/20">
                    <div class="text-xs font-medium text-emerald-700 dark:text-emerald-300">Принято за сегодня</div>
                    <div class="mt-1 text-lg font-semibold text-emerald-700 dark:text-emerald-300">{{ $this->money($totals['amount']) }}</div>
                </div>
            </div>
        @endif

        {{ $this->table }}
    </div>
</x-filament-panels::page>
