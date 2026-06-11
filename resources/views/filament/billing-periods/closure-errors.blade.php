@php
    use Filament\Support\Icons\Heroicon;

    $billingTypeLabels = [
        'fixed' => 'Фиксированная',
        'meter' => 'По счётчику',
        'per_person' => 'По проживающим',
    ];
@endphp

<div class="space-y-6">
    <x-filament::section
        icon="heroicon-o-exclamation-triangle"
        icon-color="danger"
        heading="Сводка ошибки"
        :description="$billingPeriod->failure_message ?? 'Закрытие завершилось ошибкой.'"
    >
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Расчётный месяц</div>
                <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $billingPeriod->label }}</div>
            </div>

            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-900/50 dark:bg-rose-950/20">
                <div class="text-xs font-medium text-rose-700 dark:text-rose-300">Ошибок данных</div>
                <div class="mt-1 text-lg font-semibold text-rose-700 dark:text-rose-300">{{ $errors->count() }}</div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Статус</div>
                <div class="mt-2">
                    <x-filament::badge color="danger" :icon="Heroicon::OutlinedExclamationCircle">
                        Ошибка закрытия
                    </x-filament::badge>
                </div>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section
        heading="Ошибки по абонентам"
        description="Исправьте данные по строкам ниже и запустите закрытие месяца повторно."
        :compact="true"
    >
        @if ($errors->isEmpty())
            <x-filament::empty-state
                :icon="Heroicon::OutlinedCheckCircle"
                icon-color="success"
                heading="Ошибок закрытия нет"
                description="Для этого расчётного месяца не сохранён отчёт ошибок."
            />
        @else
            <div class="fi-ta overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm ring-1 ring-gray-950/5 dark:border-white/10 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-ta-content overflow-x-auto">
                    <table class="fi-ta-table min-w-full divide-y divide-gray-200 dark:divide-white/5">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-white/5">
                                <th class="fi-ta-header-cell px-4 py-3 text-start text-sm font-semibold text-gray-950 dark:text-white">Абонент</th>
                                <th class="fi-ta-header-cell px-4 py-3 text-start text-sm font-semibold text-gray-950 dark:text-white">Тип</th>
                                <th class="fi-ta-header-cell px-4 py-3 text-start text-sm font-semibold text-gray-950 dark:text-white">Причина</th>
                                <th class="fi-ta-header-cell px-4 py-3 text-start text-sm font-semibold text-gray-950 dark:text-white">Код</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-200 whitespace-nowrap dark:divide-white/5">
                            @foreach ($errors as $error)
                                <tr class="fi-ta-row bg-white transition hover:bg-gray-50 dark:bg-gray-900 dark:hover:bg-white/5">
                                    <td class="fi-ta-cell px-4 py-4 align-top">
                                        <div class="flex flex-col gap-1">
                                            <div class="font-medium text-gray-950 dark:text-white">
                                                {{ $error->client_name ?? 'Без имени' }}
                                            </div>
                                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                                Л/с: {{ $error->account_number ?? '-' }}
                                            </div>
                                        </div>
                                    </td>

                                    <td class="fi-ta-cell px-4 py-4 align-top">
                                        <x-filament::badge color="gray">
                                            {{ $billingTypeLabels[$error->billing_type] ?? ($error->billing_type ?: '-') }}
                                        </x-filament::badge>
                                    </td>

                                    <td class="fi-ta-cell min-w-80 whitespace-normal px-4 py-4 align-top">
                                        <div class="flex flex-col gap-3">
                                            <div class="font-medium text-gray-950 dark:text-white">{{ $error->message }}</div>

                                            @if ($error->context)
                                                <dl class="grid gap-2 rounded-lg bg-gray-50 p-3 text-xs dark:bg-white/5 sm:grid-cols-2">
                                                    @foreach ($error->context as $key => $value)
                                                        @php
                                                            $contextValue = is_scalar($value)
                                                                ? (string) $value
                                                                : json_encode($value, JSON_UNESCAPED_UNICODE);
                                                        @endphp

                                                        <div class="min-w-0">
                                                            <dt class="font-medium text-gray-500 dark:text-gray-400">{{ $key }}</dt>
                                                            <dd class="mt-0.5 truncate text-gray-700 dark:text-gray-200">{{ $contextValue }}</dd>
                                                        </div>
                                                    @endforeach
                                                </dl>
                                            @endif
                                        </div>
                                    </td>

                                    <td class="fi-ta-cell px-4 py-4 align-top">
                                        <x-filament::badge color="warning">
                                            {{ $error->code }}
                                        </x-filament::badge>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </x-filament::section>
</div>
