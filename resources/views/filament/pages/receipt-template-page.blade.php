<x-filament-panels::page>
    <div class="grid gap-6 xl:grid-cols-[minmax(0,28rem)_minmax(0,1fr)]">
        <div>
            {{ $this->form }}
        </div>

        <div>
            <div class="sticky top-20">
                <h2 class="mb-3 text-sm font-semibold text-gray-500 dark:text-gray-400">
                    Предпросмотр — как квитанция будет выглядеть при печати
                </h2>

                <div class="receipt-sheet max-w-2xl overflow-x-auto rounded-xl bg-stone-100 p-4 dark:bg-white/5">
                    {!! $this->previewHtml() !!}
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
