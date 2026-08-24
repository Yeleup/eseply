@php
    use Filament\Support\Icons\Heroicon;

    $progress = $this->readingProgress();
    $remaining = max($progress['total'] - $progress['taken'], 0);
@endphp

<x-filament-panels::page>
    @if ($this->hasBillingPeriod())
        <x-filament::section
            icon="heroicon-o-clipboard-document-list"
            icon-color="primary"
            heading="Обход участка"
            :description="'Расчётный месяц: ' . $this->billingPeriodLabel() . '. Показания сохраняются по одной строке, отдельной кнопки сохранения нет.'"
        >
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Счётчиков в выборке</div>
                    <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">
                        {{ number_format($progress['total'], 0, ',', ' ') }}
                    </div>
                </div>

                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/50 dark:bg-emerald-950/20">
                    <div class="text-xs font-medium text-emerald-700 dark:text-emerald-300">Снято</div>
                    <div class="mt-1 text-lg font-semibold text-emerald-700 dark:text-emerald-300">
                        {{ number_format($progress['taken'], 0, ',', ' ') }} из {{ number_format($progress['total'], 0, ',', ' ') }}
                        <span class="text-sm font-normal">({{ $progress['percent'] }}%)</span>
                    </div>
                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-emerald-200 dark:bg-emerald-900/50">
                        <div class="h-full rounded-full bg-emerald-500 dark:bg-emerald-400" style="width: {{ $progress['percent'] }}%"></div>
                    </div>
                </div>

                @if ($progress['problem'] > 0)
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/50 dark:bg-amber-950/20">
                        <div class="text-xs font-medium text-amber-700 dark:text-amber-300">Проблемных</div>
                        <div class="mt-1 text-lg font-semibold text-amber-700 dark:text-amber-300">
                            {{ number_format($progress['problem'], 0, ',', ' ') }}
                        </div>
                        <div class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                            Расход отрицательный. Пока такие показания не исправлены, месяц закрыть нельзя — включите фильтр «Только проблемные».
                        </div>
                    </div>
                @else
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Осталось снять</div>
                        <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">
                            {{ number_format($remaining, 0, ',', ' ') }}
                        </div>
                        @if ($progress['total'] > 0 && $remaining === 0)
                            <div class="mt-2">
                                <x-filament::badge color="success" :icon="Heroicon::OutlinedCheckCircle">
                                    Все показания сняты
                                </x-filament::badge>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
