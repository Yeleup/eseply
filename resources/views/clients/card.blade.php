<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Карточка абонента {{ $client->account_number }}</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

        <style>
            @media print {
                @page {
                    size: A4 portrait;
                    margin: 6mm;
                }

                body {
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
            }
        </style>
    </head>
    <body class="bg-stone-100 text-zinc-950 antialiased dark:bg-zinc-950 dark:text-zinc-50 print:bg-white print:text-zinc-950">
        <main class="mx-auto min-h-screen w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8 print:min-h-0 print:max-w-none print:p-0">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 print:hidden">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.22em] text-teal-800 dark:text-teal-300">PDF</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight">Карточка абонента {{ $client->account_number }}</h1>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">Компактная A4-версия содержит данные абонента, счётчики, показания, оплаты, корректировки, начисления и квитанции.</p>
                </div>

                <button
                    type="button"
                    onclick="window.print()"
                    class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-white"
                >
                    Печатать PDF
                </button>
            </div>

            <article class="client-card-sheet overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900 print:overflow-visible print:rounded-none print:border-0 print:bg-white print:shadow-none print:text-[7.8px] print:leading-tight">
                <header class="border-b border-zinc-200 bg-linear-to-br from-amber-100 via-white to-teal-100 px-6 py-5 dark:border-zinc-800 dark:from-amber-950/50 dark:via-zinc-900 dark:to-teal-950/50 print:border-zinc-900 print:bg-white print:px-0 print:py-1.5">
                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem] print:grid-cols-[minmax(0,1fr)_42mm] print:gap-2">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.22em] text-teal-800 dark:text-teal-300 print:text-[7px] print:tracking-[0.16em] print:text-zinc-600">Печатная карточка</p>
                            <h2 class="mt-1 text-3xl font-semibold tracking-tight text-zinc-950 dark:text-white print:mt-0.5 print:text-[14px] print:text-zinc-950">Карточка абонента</h2>
                            <p class="mt-2 max-w-4xl text-sm text-zinc-600 dark:text-zinc-300 print:mt-1 print:text-[8px] print:text-zinc-700">
                                {{ $client->organization?->name ?? 'Организация' }} · {{ $client->name }}
                            </p>
                        </div>

                        <dl class="grid grid-cols-[5.5rem_1fr] gap-x-2 gap-y-1 rounded-2xl border border-white/80 bg-white/80 px-4 py-3 text-sm shadow-sm dark:border-zinc-800 dark:bg-zinc-900/80 print:grid-cols-[15mm_1fr] print:rounded-none print:border-0 print:bg-white print:p-0 print:text-[7.5px] print:shadow-none">
                            <dt class="text-zinc-500 dark:text-zinc-400 print:text-zinc-600">Лицевой счёт</dt>
                            <dd class="font-semibold text-zinc-950 dark:text-white print:text-zinc-950">{{ $client->account_number }}</dd>
                            <dt class="text-zinc-500 dark:text-zinc-400 print:text-zinc-600">Дата</dt>
                            <dd class="font-semibold text-zinc-950 dark:text-white print:text-zinc-950">{{ $generatedAt->format('d.m.Y H:i') }}</dd>
                        </dl>
                    </div>
                </header>

                <div class="grid gap-5 p-6 print:gap-1.5 print:p-0 print:pt-1.5">
                    <section class="break-inside-avoid-page rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800 print:rounded-none print:border print:border-zinc-900 print:p-1.5">
                        <h3 class="text-lg font-semibold print:text-[9px]">Данные абонента</h3>

                        <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 print:mt-1 print:grid-cols-3 print:gap-x-2 print:gap-y-0.5">
                            @foreach ($clientDetails as $detail)
                                <div class="break-inside-avoid rounded-xl bg-zinc-50 px-4 py-3 dark:bg-zinc-950 print:grid print:grid-cols-[0.9fr_1.1fr] print:gap-1 print:rounded-none print:border-b print:border-zinc-200 print:bg-white print:px-0 print:py-0.5">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400 print:text-[6.6px] print:normal-case print:tracking-normal print:text-zinc-600">{{ $detail['label'] }}</dt>
                                    <dd class="mt-1 text-sm font-medium text-zinc-950 dark:text-zinc-50 print:mt-0 print:text-[7.2px] print:text-zinc-950">{{ $detail['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>

                    <section class="grid grid-cols-1 gap-5 lg:grid-cols-2 print:grid-cols-2 print:gap-1.5">
                        <div class="break-inside-avoid-page rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800 print:rounded-none print:border print:border-zinc-900 print:p-1.5">
                            <h3 class="text-lg font-semibold print:text-[9px]">Адрес</h3>

                            <dl class="mt-4 grid grid-cols-1 gap-3 print:mt-1 print:gap-y-0.5">
                                @foreach ($addressDetails as $detail)
                                    <div class="break-inside-avoid rounded-xl bg-zinc-50 px-4 py-3 dark:bg-zinc-950 print:grid print:grid-cols-[0.7fr_1.3fr] print:gap-1 print:rounded-none print:border-b print:border-zinc-200 print:bg-white print:px-0 print:py-0.5">
                                        <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400 print:text-[6.6px] print:normal-case print:tracking-normal print:text-zinc-600">{{ $detail['label'] }}</dt>
                                        <dd class="mt-1 text-sm font-medium text-zinc-950 dark:text-zinc-50 print:mt-0 print:text-[7.2px] print:text-zinc-950">{{ $detail['value'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>

                        <div class="break-inside-avoid-page rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800 print:rounded-none print:border print:border-zinc-900 print:p-1.5">
                            <h3 class="text-lg font-semibold print:text-[9px]">Настройки начисления</h3>

                            <dl class="mt-4 grid grid-cols-1 gap-3 print:mt-1 print:gap-y-0.5">
                                @foreach ($billingDetails as $detail)
                                    <div class="break-inside-avoid rounded-xl bg-zinc-50 px-4 py-3 dark:bg-zinc-950 print:grid print:grid-cols-[0.7fr_1.3fr] print:gap-1 print:rounded-none print:border-b print:border-zinc-200 print:bg-white print:px-0 print:py-0.5">
                                        <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400 print:text-[6.6px] print:normal-case print:tracking-normal print:text-zinc-600">{{ $detail['label'] }}</dt>
                                        <dd class="mt-1 text-sm font-medium text-zinc-950 dark:text-zinc-50 print:mt-0 print:text-[7.2px] print:text-zinc-950">{{ $detail['value'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800 print:rounded-none print:border print:border-zinc-900 print:p-1.5">
                        <h3 class="text-lg font-semibold print:text-[9px]">Счётчики</h3>

                        <div class="mt-4 overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-800 print:mt-1 print:overflow-visible print:rounded-none print:border-zinc-900">
                            <table class="w-full min-w-[54rem] text-left text-xs print:min-w-0 print:text-[6.8px]">
                                <thead class="bg-zinc-100 text-[11px] uppercase tracking-wide text-zinc-600 dark:bg-zinc-950 dark:text-zinc-300 print:bg-white print:text-[6.2px] print:text-zinc-700">
                                    <tr>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Номер</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Услуга</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Статус</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Начальное</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Установлен</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Снят</th>
                                        <th class="border-b border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Примечание</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($meterRows as $row)
                                        <tr class="align-top odd:bg-zinc-50/70 dark:odd:bg-zinc-950/60 print:odd:bg-white">
                                            <td class="border-r border-zinc-200 px-3 py-2 font-semibold dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">{{ $row['number'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">{{ $row['utility_service'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">{{ $row['status'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">{{ $row['initial_reading'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">{{ $row['installed_on'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">{{ $row['removed_on'] }}</td>
                                            <td class="px-3 py-2 print:px-1 print:py-0.5">{{ $row['note'] }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td class="px-3 py-4 text-center text-zinc-500 print:px-1 print:py-1" colspan="7">Нет счётчиков.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800 print:rounded-none print:border print:border-zinc-900 print:p-1.5">
                        <h3 class="text-lg font-semibold print:text-[9px]">Показания счётчиков</h3>

                        <div class="mt-4 overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-800 print:mt-1 print:overflow-visible print:rounded-none print:border-zinc-900">
                            <table class="w-full min-w-[56rem] text-left text-xs print:min-w-0 print:text-[6.8px]">
                                <thead class="bg-zinc-100 text-[11px] uppercase tracking-wide text-zinc-600 dark:bg-zinc-950 dark:text-zinc-300 print:bg-white print:text-[6.2px] print:text-zinc-700">
                                    <tr>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Период</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Счётчик</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Предыдущее</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Текущее</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Расход</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Дата</th>
                                        <th class="border-b border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Примечание</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($meterReadingRows as $row)
                                        <tr class="align-top odd:bg-zinc-50/70 dark:odd:bg-zinc-950/60 print:odd:bg-white">
                                            <td class="border-r border-zinc-200 px-3 py-2 font-semibold dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">{{ $row['period'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">{{ $row['meter_number'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">{{ $row['previous_reading'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">{{ $row['current_reading'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right font-semibold dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">{{ $row['consumption'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">{{ $row['read_at'] }}</td>
                                            <td class="px-3 py-2 print:px-1 print:py-0.5">{{ $row['note'] }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td class="px-3 py-4 text-center text-zinc-500 print:px-1 print:py-1" colspan="7">Нет показаний счётчиков.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="grid grid-cols-1 gap-5 lg:grid-cols-2 print:grid-cols-2 print:gap-1.5">
                        <div class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800 print:rounded-none print:border print:border-zinc-900 print:p-1.5">
                            <h3 class="text-lg font-semibold print:text-[9px]">Оплаты</h3>

                            <div class="mt-4 overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-800 print:mt-1 print:overflow-visible print:rounded-none print:border-zinc-900">
                                <table class="w-full min-w-[34rem] text-left text-xs print:min-w-0 print:text-[6.8px]">
                                    <thead class="bg-zinc-100 text-[11px] uppercase tracking-wide text-zinc-600 dark:bg-zinc-950 dark:text-zinc-300 print:bg-white print:text-[6.2px] print:text-zinc-700">
                                        <tr>
                                            <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Период</th>
                                            <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Сумма</th>
                                            <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Дата</th>
                                            <th class="border-b border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Примечание</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($paymentRows as $row)
                                            <tr class="align-top odd:bg-zinc-50/70 dark:odd:bg-zinc-950/60 print:odd:bg-white">
                                                <td class="border-r border-zinc-200 px-3 py-2 font-semibold dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">{{ $row['period'] }}</td>
                                                <td class="border-r border-zinc-200 px-3 py-2 text-right font-semibold dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">{{ $row['amount'] }}</td>
                                                <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">{{ $row['paid_at'] }}</td>
                                                <td class="px-3 py-2 print:px-1 print:py-0.5">{{ $row['note'] }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td class="px-3 py-4 text-center text-zinc-500 print:px-1 print:py-1" colspan="4">Нет оплат.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800 print:rounded-none print:border print:border-zinc-900 print:p-1.5">
                            <h3 class="text-lg font-semibold print:text-[9px]">Корректировки сальдо</h3>

                            <div class="mt-4 overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-800 print:mt-1 print:overflow-visible print:rounded-none print:border-zinc-900">
                                <table class="w-full min-w-[38rem] text-left text-xs print:min-w-0 print:text-[6.8px]">
                                    <thead class="bg-zinc-100 text-[11px] uppercase tracking-wide text-zinc-600 dark:bg-zinc-950 dark:text-zinc-300 print:bg-white print:text-[6.2px] print:text-zinc-700">
                                        <tr>
                                            <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Период</th>
                                            <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Тип</th>
                                            <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Сумма</th>
                                            <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Дата</th>
                                            <th class="border-b border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">Примечание</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($balanceAdjustmentRows as $row)
                                            <tr class="align-top odd:bg-zinc-50/70 dark:odd:bg-zinc-950/60 print:odd:bg-white">
                                                <td class="border-r border-zinc-200 px-3 py-2 font-semibold dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">{{ $row['period'] }}</td>
                                                <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">{{ $row['type'] }}</td>
                                                <td class="border-r border-zinc-200 px-3 py-2 text-right font-semibold dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">{{ $row['amount'] }}</td>
                                                <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-1 print:py-0.5">{{ $row['adjusted_at'] }}</td>
                                                <td class="px-3 py-2 print:px-1 print:py-0.5">{{ $row['note'] }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td class="px-3 py-4 text-center text-zinc-500 print:px-1 print:py-1" colspan="5">Нет корректировок сальдо.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800 print:rounded-none print:border print:border-zinc-900 print:p-1.5">
                        <h3 class="text-lg font-semibold print:text-[9px]">Начисления</h3>

                        <div class="mt-4 overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-800 print:mt-1 print:overflow-visible print:rounded-none print:border-zinc-900">
                            <table class="w-full min-w-[76rem] text-left text-xs print:min-w-0 print:text-[6px]">
                                <thead class="bg-zinc-100 text-[11px] uppercase tracking-wide text-zinc-600 dark:bg-zinc-950 dark:text-zinc-300 print:bg-white print:text-[5.5px] print:text-zinc-700">
                                    <tr>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Период</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Услуга</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Тип</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Объём</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Тариф</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Начислено</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Оплачено</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Корр.</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Нач. сальдо</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Кон. сальдо</th>
                                        <th class="border-b border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Закрыт</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($accrualRows as $row)
                                        <tr class="align-top odd:bg-zinc-50/70 dark:odd:bg-zinc-950/60 print:odd:bg-white">
                                            <td class="border-r border-zinc-200 px-3 py-2 font-semibold dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['period'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['utility_service'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['billing_type'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['volume'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['tariff_price'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right font-semibold dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['amount'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['paid_amount'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['adjustment_amount'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['opening_balance'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right font-semibold dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['closing_balance'] }}</td>
                                            <td class="px-3 py-2 print:px-0.5 print:py-0.5">{{ $row['closed_at'] }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td class="px-3 py-4 text-center text-zinc-500 print:px-1 print:py-1" colspan="11">Нет начислений.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800 print:rounded-none print:border print:border-zinc-900 print:p-1.5">
                        <h3 class="text-lg font-semibold print:text-[9px]">Квитанции</h3>

                        <div class="mt-4 overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-800 print:mt-1 print:overflow-visible print:rounded-none print:border-zinc-900">
                            <table class="w-full min-w-[82rem] text-left text-xs print:min-w-0 print:text-[5.9px]">
                                <thead class="bg-zinc-100 text-[11px] uppercase tracking-wide text-zinc-600 dark:bg-zinc-950 dark:text-zinc-300 print:bg-white print:text-[5.4px] print:text-zinc-700">
                                    <tr>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Номер</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Период</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Услуга</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Тип</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Объём</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Тариф</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Сумма</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Оплачено</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Корр.</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Нач. сальдо</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Кон. сальдо</th>
                                        <th class="border-b border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">Дата</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($receiptRows as $row)
                                        <tr class="align-top odd:bg-zinc-50/70 dark:odd:bg-zinc-950/60 print:odd:bg-white">
                                            <td class="border-r border-zinc-200 px-3 py-2 font-semibold dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['receipt_number'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['period'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['utility_service'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['billing_type'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['volume'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['tariff_price'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right font-semibold dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['amount'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['paid_amount'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['adjustment_amount'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['opening_balance'] }}</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right font-semibold dark:border-zinc-800 print:border-zinc-900 print:px-0.5 print:py-0.5">{{ $row['closing_balance'] }}</td>
                                            <td class="px-3 py-2 print:px-0.5 print:py-0.5">{{ $row['issued_at'] }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td class="px-3 py-4 text-center text-zinc-500 print:px-1 print:py-1" colspan="12">Нет квитанций.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </article>
        </main>
    </body>
</html>
