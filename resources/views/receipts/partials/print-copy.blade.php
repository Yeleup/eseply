<article
    class="receipt-copy {{ $template->copyCssClasses() }} flex flex-col rounded-xl border border-zinc-900 bg-white p-4 text-[10px] leading-tight text-zinc-950 shadow-sm print:rounded-none print:p-2 print:shadow-none"
    data-receipt-copy="{{ $copyTitle }}"
>
    <div class="grid grid-cols-2 gap-x-3">
        @foreach ($template->enabledBlockTypes() as $blockType)
            @include('receipts.blocks.'.str_replace('_', '-', $blockType))
        @endforeach
    </div>
</article>
