{{-- Keys of the payment modal, right above its buttons. --}}
<p class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
    <span>@include('filament.pages.payment-desk.kbd', ['key' => 'Enter']) принять</span>
    <span>@include('filament.pages.payment-desk.kbd', ['key' => 'F4']) вся сумма</span>
    <span>@include('filament.pages.payment-desk.kbd', ['key' => 'Esc']) отмена</span>
    <span>@include('filament.pages.payment-desk.kbd', ['key' => 'Tab']) поля</span>
    <span>@include('filament.pages.payment-desk.kbd', ['key' => 'Ctrl']) @include('filament.pages.payment-desk.kbd', ['key' => 'Enter']) принять из примечания</span>
</p>
