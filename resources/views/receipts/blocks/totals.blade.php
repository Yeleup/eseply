@php
    $balanceDetailsByLabel = collect($balanceDetails);
    $debt = $balanceDetailsByLabel->firstWhere('label', 'Начальное сальдо')['value'] ?? '-';
    $paid = $balanceDetailsByLabel->firstWhere('label', 'Оплачено')['value'] ?? '-';
@endphp

<section class="col-span-2 mt-2 overflow-hidden rounded-lg border border-zinc-900 print:mt-1.5 print:rounded-none">
    <table class="w-full text-left text-[9px] print:text-[7.5px]">
        <tbody>
            <tr>
                <td class="border-r border-zinc-900 px-2 py-1 font-semibold print:px-1 print:py-0.5">Долг</td>
                <td class="w-28 px-2 py-1 text-right font-semibold print:px-1 print:py-0.5">{{ $debt }}</td>
            </tr>
            <tr>
                <td class="border-t border-r border-zinc-900 px-2 py-1 font-semibold print:px-1 print:py-0.5">Оплачено</td>
                <td class="border-t border-zinc-900 px-2 py-1 text-right font-semibold print:px-1 print:py-0.5">{{ $paid }}</td>
            </tr>
            <tr class="bg-zinc-50 font-bold print:bg-white">
                <td class="border-t border-r border-zinc-900 px-2 py-1.5 print:px-1 print:py-0.5">К оплате</td>
                <td class="border-t border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">{{ $paymentDue }}</td>
            </tr>
        </tbody>
    </table>
</section>
