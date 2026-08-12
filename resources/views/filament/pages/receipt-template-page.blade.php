<x-filament-panels::page>
    <div class="grid gap-6 xl:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
        <div>
            {{ $this->form }}
        </div>

        <div class="space-y-6">
            <div
                id="receipt-template-editor"
                wire:ignore
                class="min-h-[24rem] rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900"
            ></div>

            <div>
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
