<section class="col-span-2 mt-2 flex items-start justify-between gap-3 print:mt-1.5">
    <p class="text-[9px] text-zinc-700 print:text-[7.5px]">{{ $template->footerNote() }}</p>

    @if ($template->showQr() && $template->qrUrl())
        <img
            src="{{ $template->qrUrl() }}"
            alt="QR-код для оплаты"
            class="h-16 w-16 shrink-0 object-contain print:h-14 print:w-14"
        >
    @endif
</section>
