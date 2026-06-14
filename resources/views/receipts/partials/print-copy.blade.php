@php
    $volume = collect($calculationDetails)->firstWhere('label', 'Объём')['value'] ?? '-';
    $amount = collect($calculationDetails)->firstWhere('label', 'Сумма')['value'] ?? '-';
    $balanceDetailsByLabel = collect($balanceDetails);
    $debt = $balanceDetailsByLabel->firstWhere('label', 'Начальное сальдо')['value'] ?? '-';
    $paid = $balanceDetailsByLabel->firstWhere('label', 'Оплачено')['value'] ?? '-';
@endphp

<article
    class="receipt-copy flex flex-col rounded-xl border border-zinc-900 bg-white p-4 text-[10px] leading-tight text-zinc-950 shadow-sm print:rounded-none print:p-2 print:shadow-none"
    data-receipt-copy="{{ $copyTitle }}"
>
    <header class="grid grid-cols-[minmax(0,1fr)_10.5rem] gap-3 border-b border-zinc-900 pb-2 print:grid-cols-[minmax(0,1fr)_8.8rem] print:gap-2">
        <div>
            <p class="text-[9px] font-bold uppercase tracking-[0.18em] text-zinc-500 print:text-[7px]">
                {{ $copyTitle }}
            </p>
            <p class="mt-1 text-[9px] font-semibold uppercase tracking-[0.12em] text-zinc-500 print:text-[7px]">
                Квитанция на оплату коммунальной услуги
            </p>
            <h2 class="mt-1 text-base font-bold tracking-tight print:text-[11px]">
                {{ $receipt->organization?->name ?? 'Организация' }}
            </h2>
            <p class="mt-0.5 text-[10px] text-zinc-600 print:text-[8px]">
                {{ $receipt->organization?->address ?? '-' }}
            </p>
        </div>

        <dl class="grid grid-cols-[3.4rem_1fr] gap-x-1 gap-y-1 text-[10px] print:grid-cols-[2.6rem_1fr] print:text-[8px]">
            <dt class="text-zinc-500">Номер</dt>
            <dd class="font-semibold">{{ $receipt->receipt_number }}</dd>
            <dt class="text-zinc-500">Период</dt>
            <dd class="font-semibold">{{ $receipt->billingPeriod?->label ?? $receipt->period }}</dd>
            <dt class="text-zinc-500">Дата</dt>
            <dd class="font-semibold">{{ $receipt->issued_at?->format('d.m.Y') ?? '-' }}</dd>
        </dl>
    </header>

    <div class="grid grid-cols-2 gap-3 border-b border-zinc-300 py-2 print:gap-2 print:py-1.5">
        <section>
            <h3 class="text-[10px] font-bold uppercase tracking-wide print:text-[8px]">Абонент</h3>
            <dl class="mt-1 grid grid-cols-[4.8rem_1fr] gap-x-1 gap-y-0.5 text-[9px] print:grid-cols-[3.8rem_1fr] print:text-[7.5px]">
                @foreach ($clientDetails as $detail)
                    <dt class="text-zinc-500">{{ $detail['label'] }}</dt>
                    <dd class="font-semibold">{{ $detail['value'] }}</dd>
                @endforeach
            </dl>
        </section>

        <section>
            <h3 class="text-[10px] font-bold uppercase tracking-wide print:text-[8px]">Реквизиты</h3>
            <dl class="mt-1 grid grid-cols-[3.6rem_1fr] gap-x-1 gap-y-0.5 text-[9px] print:grid-cols-[2.9rem_1fr] print:text-[7.5px]">
                @foreach ($organizationDetails as $detail)
                    <dt class="text-zinc-500">{{ $detail['label'] }}</dt>
                    <dd class="font-semibold">{{ $detail['value'] }}</dd>
                @endforeach
            </dl>
        </section>
    </div>

    <section class="mt-2 print:mt-1.5">
        <div class="flex items-center justify-between gap-2">
            <h3 class="text-[10px] font-bold uppercase tracking-wide print:text-[8px]">Счётчики</h3>
            <p class="text-[8px] text-zinc-500 print:text-[7px]">
                Сформирована: {{ $generatedAt->format('d.m.Y H:i') }}
            </p>
        </div>

        <div class="mt-1 overflow-hidden rounded-lg border border-zinc-900 print:rounded-none">
            <table class="w-full text-left text-[9px] print:text-[7.5px]">
                <thead class="bg-zinc-100 uppercase tracking-wide text-zinc-600 print:bg-white print:text-[6.8px]">
                    <tr>
                        <th class="border-b border-r border-zinc-900 px-2 py-1.5 print:px-1 print:py-0.5">№ счётчика</th>
                        <th class="border-b border-r border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">Предыдущее</th>
                        <th class="border-b border-r border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">Текущее</th>
                        <th class="border-b border-r border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">Расход</th>
                        <th class="border-b border-r border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">Тариф</th>
                        <th class="border-b border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">Сумма</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($meterReadingLines as $line)
                        <tr>
                            <td class="border-r border-zinc-900 px-2 py-1.5 font-semibold print:px-1 print:py-0.5">
                                {{ $line['meter_number'] }}
                            </td>
                            <td class="border-r border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">
                                {{ $line['previous_reading'] }}
                            </td>
                            <td class="border-r border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">
                                {{ $line['current_reading'] }}
                            </td>
                            <td class="border-r border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">
                                {{ $line['consumption'] }}
                            </td>
                            <td class="border-r border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">
                                {{ $line['tariff_price'] }}
                            </td>
                            <td class="px-2 py-1.5 text-right font-bold print:px-1 print:py-0.5">
                                {{ $line['amount'] }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-2 py-1.5 text-center text-zinc-500 print:px-1 print:py-0.5" colspan="6">
                                Нет показаний счётчиков
                            </td>
                        </tr>
                    @endforelse

                    <tr class="bg-zinc-50 font-bold print:bg-white">
                        <td class="border-t border-r border-zinc-900 px-2 py-1.5 print:px-1 print:py-0.5" colspan="3">
                            Итого
                        </td>
                        <td class="border-t border-r border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">
                            {{ $volume }}
                        </td>
                        <td class="border-t border-r border-zinc-900 px-2 py-1.5 print:px-1 print:py-0.5"></td>
                        <td class="border-t border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">
                            {{ $amount }}
                        </td>
                    </tr>
                    <tr>
                        <td class="border-t border-r border-zinc-900 px-2 py-1 font-semibold print:px-1 print:py-0.5" colspan="5">
                            Долг
                        </td>
                        <td class="border-t border-zinc-900 px-2 py-1 text-right font-semibold print:px-1 print:py-0.5">
                            {{ $debt }}
                        </td>
                    </tr>
                    <tr>
                        <td class="border-t border-r border-zinc-900 px-2 py-1 font-semibold print:px-1 print:py-0.5" colspan="5">
                            Оплачено
                        </td>
                        <td class="border-t border-zinc-900 px-2 py-1 text-right font-semibold print:px-1 print:py-0.5">
                            {{ $paid }}
                        </td>
                    </tr>
                    <tr class="bg-zinc-50 font-bold print:bg-white">
                        <td class="border-t border-r border-zinc-900 px-2 py-1.5 print:px-1 print:py-0.5" colspan="5">
                            К оплате
                        </td>
                        <td class="border-t border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">
                            {{ $paymentDue }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

</article>
