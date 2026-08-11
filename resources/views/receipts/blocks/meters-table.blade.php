@php
    $volume = collect($calculationDetails)->firstWhere('label', 'Объём')['value'] ?? '-';
    $amount = collect($calculationDetails)->firstWhere('label', 'Сумма')['value'] ?? '-';
@endphp

<section class="col-span-2 mt-2 print:mt-1.5">
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
            </tbody>
        </table>
    </div>
</section>
