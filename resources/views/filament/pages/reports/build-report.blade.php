@php
    $build = $this->reportBuild();
    $fieldOptions = $this->fieldOptions();
    $billingPeriodOptions = $this->billingPeriodOptions();
    $selectedBillingPeriodId = $build->billingPeriod?->getKey();
    $isSummary = $build->isSummary();
@endphp

<x-filament-panels::page>
    <div class="fi-report-builder grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)]">
        <div class="flex flex-col gap-4">
            <x-filament::section
                heading="1. Источник данных"
                description="Источник задаёт колонки, измерения и показатели."
            >
                <div class="flex flex-col gap-2" role="radiogroup" aria-label="Источник данных">
                    @foreach ($this->sourceOptions() as $option)
                        <label
                            wire:key="report-builder-source-{{ $option['key'] }}-{{ $option['active'] ? 'on' : 'off' }}"
                            @class([
                                'flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-2.5 transition',
                                'border-primary-600 bg-primary-50 dark:border-primary-400 dark:bg-primary-400/10' => $option['active'],
                                'border-gray-200 bg-white hover:border-gray-300 dark:border-white/10 dark:bg-white/5 dark:hover:border-white/20' => ! $option['active'],
                            ])
                        >
                            <x-filament::input.radio
                                name="report-builder-source"
                                value="{{ $option['key'] }}"
                                :checked="$option['active']"
                                wire:click="selectSource(@js($option['key']))"
                                class="mt-0.5"
                            />

                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-gray-950 dark:text-white">{{ $option['label'] }}</span>
                                <span class="mt-0.5 block text-xs text-gray-600 dark:text-gray-400">{{ $option['hint'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section heading="2. Колонки">
                <x-slot name="afterHeader">
                    <span class="text-xs text-gray-600 dark:text-gray-400">{{ $this->fieldsCounter() }}</span>
                </x-slot>

                <div class="flex flex-col gap-1">
                    @foreach ($fieldOptions as $field)
                        <label
                            wire:key="report-builder-field-{{ $build->source->key() }}-{{ $field['key'] }}-{{ $field['checked'] ? 'on' : 'off' }}"
                            @class([
                                'flex items-center gap-3 rounded-lg px-2 py-1.5 text-sm text-gray-950 dark:text-white',
                                'cursor-pointer hover:bg-gray-50 dark:hover:bg-white/5' => ! $field['locked'],
                                'cursor-not-allowed' => $field['locked'],
                            ])
                            @if ($field['locked']) title="Последнюю колонку нельзя убрать" @endif
                        >
                            <x-filament::input.checkbox
                                :checked="$field['checked']"
                                :disabled="$field['locked']"
                                wire:click="toggleField(@js($field['key']))"
                            />

                            <span class="min-w-0 flex-1">{{ $field['label'] }}</span>

                            @if ($field['computed'])
                                <span class="shrink-0 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
                                    {{ $field['kind'] }}
                                </span>
                            @else
                                <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $field['kind'] }}</span>
                            @endif
                        </label>
                    @endforeach
                </div>

                <p class="mt-3 text-xs text-gray-600 dark:text-gray-400">
                    Колонки идут в порядке каталога, а не в порядке выбора. Вычисляемые колонки считает база: они сортируются и попадают в XLSX, деление на ноль даёт прочерк «—».
                </p>
            </x-filament::section>

            <x-filament::section heading="3. Группировка">
                <label for="report-builder-dimension" class="block text-sm font-medium text-gray-950 dark:text-white">Группировать по</label>

                <x-filament::input.wrapper class="mt-1">
                    <x-filament::input.select
                        id="report-builder-dimension"
                        wire:change="selectDimension($event.target.value)"
                    >
                        @foreach ($this->dimensionOptions() as $key => $label)
                            <option value="{{ $key }}" @selected($key === $this->selectedDimensionKey())>{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>

                <div class="mt-4">
                    <div class="text-sm font-medium text-gray-950 dark:text-white">Показатели в сводке</div>

                    <div class="mt-1 flex flex-col gap-1">
                        @foreach ($this->metricOptions() as $metric)
                            <label
                                wire:key="report-builder-metric-{{ $build->source->key() }}-{{ $metric['key'] }}-{{ $metric['checked'] ? 'on' : 'off' }}"
                                class="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-1.5 text-sm text-gray-950 hover:bg-gray-50 dark:text-white dark:hover:bg-white/5"
                            >
                                <x-filament::input.checkbox
                                    :checked="$metric['checked']"
                                    wire:click="toggleMetric(@js($metric['key']))"
                                />

                                <span>{{ $metric['label'] }}</span>
                            </label>
                        @endforeach
                    </div>

                    <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                        Суммы в сводке складываются, проценты и средние пересчитываются от итогов группы — как «Процент снятия» в готовых отчётах.
                    </p>
                </div>
            </x-filament::section>
        </div>

        <div class="flex min-w-0 flex-col gap-4">
            <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 sm:flex-row sm:items-end sm:justify-between">
                <div class="flex flex-col gap-2">
                    <x-filament::tabs label="Режим отчёта">
                        <x-filament::tabs.item
                            :active="! $isSummary"
                            wire:click="selectMode('detail')"
                        >
                            Детально
                        </x-filament::tabs.item>

                        <x-filament::tabs.item
                            :active="$build->mode === \App\Reports\Builder\ReportBuild::MODE_SUMMARY"
                            wire:click="selectMode('summary')"
                        >
                            Сводно
                        </x-filament::tabs.item>
                    </x-filament::tabs>

                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ $this->previewNote() }}</p>
                </div>

                <div class="w-full sm:w-64">
                    <label for="report-builder-period" class="block text-sm font-medium text-gray-950 dark:text-white">Расчётный месяц</label>

                    <x-filament::input.wrapper class="mt-1">
                        <x-filament::input.select
                            id="report-builder-period"
                            wire:change="selectBillingPeriod($event.target.value)"
                        >
                            @if ($billingPeriodOptions === [])
                                <option value="">Расчётных месяцев нет</option>
                            @endif

                            @foreach ($billingPeriodOptions as $billingPeriodId => $label)
                                <option value="{{ $billingPeriodId }}" @selected($billingPeriodId === $selectedBillingPeriodId)>{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            </div>

            @if ($this->isSummaryLocked())
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
                    Выберите измерение в «Группировка», чтобы собрать сводку.
                </div>
            @endif

            {{ $this->table }}

            <p class="text-xs text-gray-600 dark:text-gray-400">
                Фильтры действуют и в детальном, и в сводном режиме. XLSX выгружает все строки с теми же фильтрами и теми же колонками, что на экране; поиск по таблице в выгрузку не попадает.
            </p>
        </div>
    </div>
</x-filament-panels::page>
