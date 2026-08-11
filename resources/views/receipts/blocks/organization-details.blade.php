<section class="border-b border-zinc-300 py-2 print:py-1.5">
    <h3 class="text-[10px] font-bold uppercase tracking-wide print:text-[8px]">Реквизиты</h3>
    <dl class="mt-1 grid grid-cols-[3.6rem_1fr] gap-x-1 gap-y-0.5 text-[9px] print:grid-cols-[2.9rem_1fr] print:text-[7.5px]">
        @foreach ($organizationDetails as $detail)
            <dt class="text-zinc-500">{{ $detail['label'] }}</dt>
            <dd class="font-semibold">{{ $detail['value'] }}</dd>
        @endforeach
    </dl>
</section>
