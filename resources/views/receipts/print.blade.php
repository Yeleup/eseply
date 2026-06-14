<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Квитанция {{ $receipt->receipt_number }}</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="bg-stone-100 text-zinc-950 antialiased print:bg-white">
        <main class="mx-auto min-h-screen w-full max-w-5xl px-4 py-6 sm:px-6 lg:px-8 print:min-h-0 print:max-w-none print:p-0">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 print:hidden">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.22em] text-teal-800">PDF</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight">Квитанция {{ $receipt->receipt_number }}</h1>
                </div>

                <button
                    type="button"
                    onclick="window.print()"
                    class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-800"
                >
                    Печатать PDF
                </button>
            </div>

            <article class="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm print:rounded-none print:border-0 print:shadow-none">
                <header class="border-b border-zinc-900 px-6 py-5 print:px-0 print:py-3">
                    <div class="grid grid-cols-1 gap-5 md:grid-cols-[1fr_18rem] print:grid-cols-[1fr_15rem]">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-zinc-500 print:text-[10px]">
                                Квитанция на оплату коммунальной услуги
                            </p>
                            <h2 class="mt-2 text-2xl font-bold tracking-tight print:text-xl">
                                {{ $receipt->organization?->name ?? 'Организация' }}
                            </h2>
                            <p class="mt-2 text-sm text-zinc-600 print:text-xs">
                                {{ $receipt->organization?->address ?? '-' }}
                            </p>
                        </div>

                        <div class="rounded-2xl border border-zinc-300 p-4 text-sm print:rounded-none print:p-3 print:text-xs">
                            <dl class="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-2 print:grid-cols-[6rem_1fr]">
                                <dt class="text-zinc-500">Номер</dt>
                                <dd class="font-semibold">{{ $receipt->receipt_number }}</dd>
                                <dt class="text-zinc-500">Период</dt>
                                <dd class="font-semibold">{{ $receipt->billingPeriod?->label ?? $receipt->period }}</dd>
                                <dt class="text-zinc-500">Дата</dt>
                                <dd class="font-semibold">{{ $receipt->issued_at?->format('d.m.Y') ?? '-' }}</dd>
                            </dl>
                        </div>
                    </div>
                </header>

                <div class="grid grid-cols-1 gap-5 px-6 py-5 lg:grid-cols-2 print:grid-cols-2 print:px-0 print:py-4">
                    <section class="break-inside-avoid-page rounded-2xl border border-zinc-200 p-5 print:rounded-none print:border-zinc-300 print:p-3">
                        <h3 class="text-base font-bold print:text-sm">Абонент</h3>
                        <dl class="mt-4 grid grid-cols-1 gap-3 text-sm print:mt-3 print:gap-2 print:text-xs">
                            @foreach ($clientDetails as $detail)
                                <div class="grid grid-cols-[9rem_1fr] gap-3 print:grid-cols-[7rem_1fr]">
                                    <dt class="text-zinc-500">{{ $detail['label'] }}</dt>
                                    <dd class="font-semibold">{{ $detail['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>

                    <section class="break-inside-avoid-page rounded-2xl border border-zinc-200 p-5 print:rounded-none print:border-zinc-300 print:p-3">
                        <h3 class="text-base font-bold print:text-sm">Реквизиты</h3>
                        <dl class="mt-4 grid grid-cols-1 gap-3 text-sm print:mt-3 print:gap-2 print:text-xs">
                            @foreach ($organizationDetails as $detail)
                                <div class="grid grid-cols-[7rem_1fr] gap-3">
                                    <dt class="text-zinc-500">{{ $detail['label'] }}</dt>
                                    <dd class="font-semibold">{{ $detail['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                </div>

                <section class="px-6 pb-5 print:px-0 print:pb-4">
                    <h3 class="mb-3 text-base font-bold print:text-sm">Счётчики</h3>
                    <div class="overflow-hidden rounded-2xl border border-zinc-900 print:rounded-none">
                        <table class="w-full text-left text-sm print:text-xs">
                            <thead class="bg-zinc-100 text-xs uppercase tracking-wide text-zinc-600 print:bg-white print:text-[10px]">
                                <tr>
                                    <th class="border-b border-r border-zinc-900 px-4 py-3 print:px-2 print:py-2">№ счётчика</th>
                                    <th class="border-b border-r border-zinc-900 px-4 py-3 text-right print:px-2 print:py-2">Предыдущее</th>
                                    <th class="border-b border-r border-zinc-900 px-4 py-3 text-right print:px-2 print:py-2">Текущее</th>
                                    <th class="border-b border-r border-zinc-900 px-4 py-3 text-right print:px-2 print:py-2">Расход</th>
                                    <th class="border-b border-r border-zinc-900 px-4 py-3 text-right print:px-2 print:py-2">Тариф</th>
                                    <th class="border-b border-zinc-900 px-4 py-3 text-right print:px-2 print:py-2">Сумма</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $volume = collect($calculationDetails)->firstWhere('label', 'Объём')['value'] ?? '-';
                                    $amount = collect($calculationDetails)->firstWhere('label', 'Сумма')['value'] ?? '-';
                                    $balanceDetailsByLabel = collect($balanceDetails);
                                    $debt = $balanceDetailsByLabel->firstWhere('label', 'Начальное сальдо')['value'] ?? '-';
                                    $paid = $balanceDetailsByLabel->firstWhere('label', 'Оплачено')['value'] ?? '-';
                                @endphp

                                @forelse ($meterReadingLines as $line)
                                    <tr>
                                        <td class="border-r border-zinc-900 px-4 py-4 font-semibold print:px-2 print:py-2">
                                            {{ $line['meter_number'] }}
                                        </td>
                                        <td class="border-r border-zinc-900 px-4 py-4 text-right print:px-2 print:py-2">
                                            {{ $line['previous_reading'] }}
                                        </td>
                                        <td class="border-r border-zinc-900 px-4 py-4 text-right print:px-2 print:py-2">
                                            {{ $line['current_reading'] }}
                                        </td>
                                        <td class="border-r border-zinc-900 px-4 py-4 text-right print:px-2 print:py-2">
                                            {{ $line['consumption'] }}
                                        </td>
                                        <td class="border-r border-zinc-900 px-4 py-4 text-right print:px-2 print:py-2">
                                            {{ $line['tariff_price'] }}
                                        </td>
                                        <td class="px-4 py-4 text-right font-bold print:px-2 print:py-2">
                                            {{ $line['amount'] }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td class="px-4 py-4 text-center text-zinc-500 print:px-2 print:py-2" colspan="6">
                                            Нет показаний счётчиков
                                        </td>
                                    </tr>
                                @endforelse

                                <tr class="bg-zinc-50 font-bold print:bg-white">
                                    <td class="border-t border-r border-zinc-900 px-4 py-3 print:px-2 print:py-2" colspan="3">
                                        Итого
                                    </td>
                                    <td class="border-t border-r border-zinc-900 px-4 py-3 text-right print:px-2 print:py-2">
                                        {{ $volume }}
                                    </td>
                                    <td class="border-t border-r border-zinc-900 px-4 py-3 print:px-2 print:py-2"></td>
                                    <td class="border-t border-zinc-900 px-4 py-3 text-right print:px-2 print:py-2">
                                        {{ $amount }}
                                    </td>
                                </tr>
                                <tr>
                                    <td class="border-t border-r border-zinc-900 px-4 py-3 font-semibold print:px-2 print:py-2" colspan="5">
                                        Долг
                                    </td>
                                    <td class="border-t border-zinc-900 px-4 py-3 text-right font-semibold print:px-2 print:py-2">
                                        {{ $debt }}
                                    </td>
                                </tr>
                                <tr>
                                    <td class="border-t border-r border-zinc-900 px-4 py-3 font-semibold print:px-2 print:py-2" colspan="5">
                                        Оплачено
                                    </td>
                                    <td class="border-t border-zinc-900 px-4 py-3 text-right font-semibold print:px-2 print:py-2">
                                        {{ $paid }}
                                    </td>
                                </tr>
                                <tr class="bg-zinc-50 font-bold print:bg-white">
                                    <td class="border-t border-r border-zinc-900 px-4 py-3 print:px-2 print:py-2" colspan="5">
                                        К оплате
                                    </td>
                                    <td class="border-t border-zinc-900 px-4 py-3 text-right print:px-2 print:py-2">
                                        {{ $paymentDue }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </article>
        </main>

        <script>
            window.addEventListener('load', () => {
                window.print();
            });
        </script>
    </body>
</html>
