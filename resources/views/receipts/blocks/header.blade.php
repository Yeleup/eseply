<header class="col-span-2 grid grid-cols-[minmax(0,1fr)_auto] gap-3 border-b border-zinc-900 pb-2 print:gap-2">
    <div>
        <p class="text-[9px] font-bold uppercase tracking-[0.18em] text-zinc-500 print:text-[7px]">
            {{ $copyTitle }}
        </p>
        <p class="mt-1 text-[9px] font-semibold uppercase tracking-[0.12em] text-zinc-500 print:text-[7px]">
            {{ $template->title() }}
        </p>
        <h2 class="mt-1 text-base font-bold tracking-tight print:text-[11px]">
            {{ $receipt->organization?->name ?? 'Организация' }}
        </h2>
        <p class="mt-0.5 text-[10px] text-zinc-600 print:text-[8px]">
            {{ $receipt->organization?->address ?? '-' }}
        </p>
    </div>

    <div class="flex items-start gap-2">
        @if ($template->showLogo() && $template->logoUrl())
            <img
                src="{{ $template->logoUrl() }}"
                alt="Логотип организации"
                class="h-10 w-10 shrink-0 object-contain print:h-8 print:w-8"
            >
        @endif

        <dl class="grid w-[10.5rem] grid-cols-[3.4rem_1fr] gap-x-1 gap-y-1 text-[10px] print:w-[8.8rem] print:grid-cols-[2.6rem_1fr] print:text-[8px]">
            <dt class="text-zinc-500">Номер</dt>
            <dd class="font-semibold">{{ $receipt->receipt_number }}</dd>
            <dt class="text-zinc-500">Период</dt>
            <dd class="font-semibold">{{ $receipt->billingPeriod?->label ?? $receipt->period }}</dd>
            <dt class="text-zinc-500">Дата</dt>
            <dd class="font-semibold">{{ $receipt->issued_at?->format('d.m.Y') ?? '-' }}</dd>
        </dl>
    </div>
</header>
