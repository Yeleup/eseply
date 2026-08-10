@php
    use Filament\Support\Icons\Heroicon;

    $billingPeriod = $this->getBillingPeriod();
    $codeSummary = $this->getCodeSummary();
    $totalErrors = $codeSummary->sum('total');
@endphp

<x-filament-panels::page>
    <x-filament::section
        icon="heroicon-o-exclamation-triangle"
        icon-color="danger"
        heading="Сводка ошибки"
        description="Исправьте данные по строкам ниже и запустите закрытие месяца повторно."
    >
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Расчётный месяц</div>
                <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $billingPeriod->label }}</div>
            </div>

            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-900/50 dark:bg-rose-950/20">
                <div class="text-xs font-medium text-rose-700 dark:text-rose-300">Ошибок данных</div>
                <div class="mt-1 text-lg font-semibold text-rose-700 dark:text-rose-300">{{ number_format($totalErrors, 0, ',', ' ') }}</div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Статус</div>
                <div class="mt-2">
                    <x-filament::badge :color="$billingPeriod->status->color()" :icon="Heroicon::OutlinedExclamationCircle">
                        {{ $billingPeriod->status->getLabel() }}
                    </x-filament::badge>
                </div>
            </div>
        </div>
    </x-filament::section>

    @if ($codeSummary->isNotEmpty())
        <x-filament::section
            heading="Причины ошибок"
            description="Сколько абонентов остановились на каждой причине."
            :collapsible="true"
        >
            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($codeSummary as $summary)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-950 dark:text-white">{{ $summary['label'] }}</div>
                            <div class="mt-1">
                                <x-filament::badge color="warning">{{ $summary['code'] }}</x-filament::badge>
                            </div>
                        </div>

                        <div class="text-lg font-semibold text-gray-950 dark:text-white">
                            {{ number_format($summary['total'], 0, ',', ' ') }}
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
