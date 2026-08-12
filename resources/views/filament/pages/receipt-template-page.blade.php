<x-filament-panels::page>
    <div class="grid gap-6 xl:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
        <div>
            {{ $this->form }}
        </div>

        <div class="space-y-6">
            <div
                id="receipt-template-editor"
                wire:ignore
                x-data="{
                    editor: null,
                    init() {
                        this.editor = window.initReceiptTemplateEditor(
                            this.$refs.canvas,
                            @js($this->editorConfig()),
                            () => ({ html: @js($this->templateHtml), css: @js($this->templateCss) }),
                        );
                    },
                    async apply() {
                        await this.$wire.set('templateHtml', this.editor.getHtml(), false);
                        await this.$wire.set('templateCss', this.editor.getCss(), false);
                    },
                    async applyAndSave() {
                        await this.apply();
                        await this.$wire.call('save');
                    },
                    async applyAndPreview() {
                        await this.apply();
                        await this.$wire.$refresh();
                    },
                }"
            >
                <div class="mb-3 flex flex-wrap gap-3">
                    <x-filament::button x-on:click="applyAndSave">Сохранить шаблон</x-filament::button>
                    <x-filament::button color="gray" x-on:click="applyAndPreview">Обновить предпросмотр</x-filament::button>
                </div>

                <div x-ref="canvas" class="rounded-xl border border-gray-200 dark:border-white/10"></div>
            </div>

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

    @push('scripts')
        @vite('resources/js/receipt-template-editor.js')
    @endpush
</x-filament-panels::page>
