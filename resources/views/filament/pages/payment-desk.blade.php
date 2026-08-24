@php
    use Filament\Support\Icons\Heroicon;

    $selectedClient = $this->selectedClient();
    $balance = $this->balance();
    $totals = $this->todayTotals();
    $results = $this->searchResults();
@endphp

<x-filament-panels::page>
    <div
        x-data
        x-on:payment-desk-reset.window="$nextTick(() => document.getElementById('payment-desk-search')?.focus())"
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
                class="fi-input w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-base text-gray-950 shadow-sm outline-none transition focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-white/20 dark:bg-white/5 dark:text-white"
            >

            @if (filled(trim($this->search)) && $results->isEmpty())
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                    Ничего не найдено. Проверьте лицевой счёт или попробуйте фамилию.
                </p>
            @endif

            @if ($results->isNotEmpty())
                <ul class="mt-3 divide-y divide-gray-200 overflow-hidden rounded-xl border border-gray-200 dark:divide-white/10 dark:border-white/10">
                    @foreach ($results as $result)
                        @php $closing = $this->closingBalanceOf($result); @endphp
                        <li>
                            <button
                                type="button"
                                wire:click="selectClient({{ $result->getKey() }})"
                                class="flex w-full flex-col gap-1 px-4 py-3 text-left transition hover:bg-gray-50 sm:flex-row sm:items-center sm:justify-between dark:hover:bg-white/5"
                            >
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold text-gray-950 dark:text-white">
                                        {{ $result->account_number }} — {{ $result->name }}
                                    </span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                                        {{ collect([$result->street?->name, filled($result->house) ? 'д. ' . $result->house : null, filled($result->apartment) ? 'кв. ' . $result->apartment : null])->filter()->implode(', ') ?: 'Адрес не указан' }}
                                        @if (filled($result->phone)) · {{ $result->phone }} @endif
                                    </span>
                                </span>

                                <span class="shrink-0">
                                    @if ($closing > 0)
                                        <x-filament::badge color="danger">Долг {{ $this->money($closing) }}</x-filament::badge>
                                    @elseif ($closing < 0)
                                        <x-filament::badge color="info">Переплата {{ $this->money(abs($closing)) }}</x-filament::badge>
                                    @else
                                        <x-filament::badge color="success">Долга нет</x-filament::badge>
                                    @endif
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        @if ($selectedClient)
            <x-filament::section
                :heading="$selectedClient->account_number . ' — ' . $selectedClient->name"
                :description="collect([$selectedClient->street?->name, filled($selectedClient->house) ? 'д. ' . $selectedClient->house : null, filled($selectedClient->apartment) ? 'кв. ' . $selectedClient->apartment : null])->filter()->implode(', ') ?: 'Адрес не указан'"
            >
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Сальдо на начало</div>
                        <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $this->money($balance['opening']) }}</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Начислено</div>
                        <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $this->money($balance['accrued']) }}</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Оплачено</div>
                        <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $this->money($balance['paid']) }}</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Корректировки</div>
                        <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $this->money($balance['adjustment']) }}</div>
                    </div>

                    @if ($balance['credit'] > 0)
                        <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 dark:border-sky-900/50 dark:bg-sky-950/20">
                            <div class="text-xs font-medium text-sky-700 dark:text-sky-300">Переплата</div>
                            <div class="mt-1 text-lg font-semibold text-sky-700 dark:text-sky-300">{{ $this->money($balance['credit']) }}</div>
                        </div>
                    @elseif ($balance['debt'] > 0)
                        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-900/50 dark:bg-rose-950/20">
                            <div class="text-xs font-medium text-rose-700 dark:text-rose-300">К оплате</div>
                            <div class="mt-1 text-lg font-semibold text-rose-700 dark:text-rose-300">{{ $this->money($balance['debt']) }}</div>
                        </div>
                    @else
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/50 dark:bg-emerald-950/20">
                            <div class="text-xs font-medium text-emerald-700 dark:text-emerald-300">Состояние</div>
                            <div class="mt-2">
                                <x-filament::badge color="success" :icon="Heroicon::OutlinedCheckCircle">Долга нет</x-filament::badge>
                            </div>
                        </div>
                    @endif
                </div>

                @if ($selectedClient->status !== 'active')
                    <div class="mt-4">
                        <x-filament::badge color="warning">Абонент не активен</x-filament::badge>
                    </div>
                @endif

                <div class="mt-6">
                    {{ $this->form }}
                </div>
            </x-filament::section>
        @endif

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
