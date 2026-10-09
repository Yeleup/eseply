@php
    $chip = 'filament.pages.payment-desk.kbd';
@endphp

{{-- The full key map, opened by F1 or the «Горячие клавиши» button. Client-only, so the server render leaves it alone. --}}
<div wire:ignore x-show="hotkeysOpen" x-cloak>
    <x-filament::section id="payment-desk-hotkeys" heading="Горячие клавиши">
        <x-slot name="afterHeader">
            <x-filament::link tag="button" color="gray" x-on:click="hotkeysOpen = false">
                Скрыть
            </x-filament::link>
        </x-slot>

        <div class="grid gap-6 md:grid-cols-2">
            <div>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">В поиске</h3>

                <dl class="mt-3 flex flex-col gap-2 text-sm">
                    <div class="flex items-baseline gap-3">
                        <dt class="w-32 shrink-0">@include($chip, ['key' => '↓']) @include($chip, ['key' => '↑'])</dt>
                        <dd class="text-gray-700 dark:text-gray-300">следующий, предыдущий абонент</dd>
                    </div>
                    <div class="flex items-baseline gap-3">
                        <dt class="w-32 shrink-0">@include($chip, ['key' => 'Enter'])</dt>
                        <dd class="text-gray-700 dark:text-gray-300">оплатить выделенного абонента; у абонента без долга приём не открывается</dd>
                    </div>
                    <div class="flex items-baseline gap-3">
                        <dt class="w-32 shrink-0">@include($chip, ['key' => 'Esc'])</dt>
                        <dd class="text-gray-700 dark:text-gray-300">очистить поиск</dd>
                    </div>
                    <div class="flex items-baseline gap-3">
                        <dt class="w-32 shrink-0">@include($chip, ['key' => '⌫'])</dt>
                        <dd class="text-gray-700 dark:text-gray-300">после оплаты счёт выделен целиком — одно нажатие стирает весь поиск</dd>
                    </div>
                    <div class="flex items-baseline gap-3">
                        <dt class="w-32 shrink-0">@include($chip, ['key' => 'F1'])</dt>
                        <dd class="text-gray-700 dark:text-gray-300">показать, скрыть эту справку</dd>
                    </div>
                </dl>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">В окне приёма</h3>

                <dl class="mt-3 flex flex-col gap-2 text-sm">
                    <div class="flex items-baseline gap-3">
                        <dt class="w-32 shrink-0">@include($chip, ['key' => 'Enter'])</dt>
                        <dd class="text-gray-700 dark:text-gray-300">пустая сумма — подставить весь долг, иначе принять оплату</dd>
                    </div>
                    <div class="flex items-baseline gap-3">
                        <dt class="w-32 shrink-0">@include($chip, ['key' => 'F4'])</dt>
                        <dd class="text-gray-700 dark:text-gray-300">вся сумма долга</dd>
                    </div>
                    <div class="flex items-baseline gap-3">
                        <dt class="w-32 shrink-0">@include($chip, ['key' => 'Tab']) @include($chip, ['key' => 'Shift']) @include($chip, ['key' => 'Tab'])</dt>
                        <dd class="text-gray-700 dark:text-gray-300">сумма, способ оплаты, дата, примечание</dd>
                    </div>
                    <div class="flex items-baseline gap-3">
                        <dt class="w-32 shrink-0">@include($chip, ['key' => 'Ctrl']) @include($chip, ['key' => 'Enter'])</dt>
                        <dd class="text-gray-700 dark:text-gray-300">принять из примечания, на Mac — @include($chip, ['key' => '⌘']) @include($chip, ['key' => 'Enter']); просто Enter переносит строку</dd>
                    </div>
                    <div class="flex items-baseline gap-3">
                        <dt class="w-32 shrink-0">@include($chip, ['key' => 'Esc'])</dt>
                        <dd class="text-gray-700 dark:text-gray-300">отмена, курсор снова в поиске</dd>
                    </div>
                </dl>
            </div>
        </div>
    </x-filament::section>
</div>
