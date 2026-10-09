@php
    $totals = $this->todayTotals();
    $results = $this->searchResults();
    $term = trim($this->search);
    $hasBillingPeriod = $this->hasBillingPeriod();
    $chip = 'filament.pages.payment-desk.kbd';
@endphp

{{--
    The keyboard state lives on the page root, so the header button, the search and the
    payment modal share it, and the listeners go away with the page.

    The highlighted row is kept here, on the client: the arrows never ask the server.
    Enter presses the «Оплатить» button of that row, the same action as the mouse.
    The modal's focus trap hands the focus back as it closes, so the search takes it on a later tick.
--}}
<x-filament-panels::page
    x-data="{
        activeRow: 0,
        hotkeysOpen: false,
        notice: '',
        afterPaymentHint: '',

        searchField() {
            return document.getElementById('payment-desk-search')
        },

        searchState() {
            return document.getElementById('payment-desk-search-state')
        },

        rows() {
            return Array.from(document.querySelectorAll('#payment-desk-results [data-payment-desk-row]'))
        },

        isActiveRow(el) {
            return Number(el.closest('[data-payment-desk-row]')?.dataset.paymentDeskRow) === this.activeRow
        },

        focusSearch() {
            setTimeout(() => setTimeout(() => {
                const search = this.searchField()

                search?.focus()
                search?.select()
            }))
        },

        afterPayment(detail) {
            const term = this.searchField()?.value.trim() ?? ''

            this.afterPaymentHint = term === String(detail?.account ?? '') ? 'Счёт ' + term : 'Поиск «' + term + '»'
            this.focusSearch()
        },

        onSearchKeydown(event) {
            if (event.key !== 'F1') {
                this.afterPaymentHint = ''
            }

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault()
                this.moveHighlight(event.key === 'ArrowDown' ? 1 : -1)
            } else if (event.key === 'Enter' && ! event.isComposing) {
                event.preventDefault()
                this.payHighlighted()
            } else if (event.key === 'Escape') {
                event.preventDefault()
                this.clearSearch()
            }
        },

        moveHighlight(step) {
            const rows = this.rows()

            if (rows.length === 0) {
                return
            }

            this.notice = ''
            this.activeRow = Math.min(Math.max(Math.min(this.activeRow, rows.length - 1) + step, 0), rows.length - 1)
            rows[this.activeRow].scrollIntoView({ block: 'nearest' })
        },

        async payHighlighted() {
            const term = this.searchField().value.trim()

            // The list follows the typing 300 ms late: send the search now rather
            // than pay a row of the previous one.
            if (this.searchState().dataset.paymentDeskTerm !== term) {
                await this.$wire.$commit()
                await this.$nextTick()

                if (this.searchState().dataset.paymentDeskTerm !== this.searchField().value.trim()) {
                    return
                }
            }

            const rows = this.rows()
            const row = rows[Math.min(this.activeRow, rows.length - 1)]

            if (! row) {
                return
            }

            const payButton = row.querySelector('[data-payment-desk-pay-button]')

            if (payButton) {
                this.notice = ''
                payButton.click()

                return
            }

            this.notice = this.searchState().dataset.paymentDeskBillingOpen === '1'
                ? row.dataset.paymentDeskAccount + ' — долга нет, приём оплаты не требуется.'
                : 'Расчётный месяц не открыт: приём оплат недоступен.'
        },

        clearSearch() {
            this.notice = ''
            this.activeRow = 0

            if (this.searchField().value !== '' || this.$wire.search !== '') {
                this.$wire.$set('search', '')
            }
        },

        onPayModalKeydown(event) {
            const form = event.currentTarget
            const field = event.target

            if (event.key === 'F4') {
                event.preventDefault()
                this.fillFullDebt(form)

                return
            }

            if (event.key !== 'Enter' || event.isComposing) {
                return
            }

            if (field.tagName === 'TEXTAREA') {
                if (event.ctrlKey || event.metaKey) {
                    event.preventDefault()
                    this.submitPayment(form)
                }

                return
            }

            // Buttons keep their own Enter: «Отмена» cancels, «Вся сумма» fills.
            if (! ['INPUT', 'SELECT'].includes(field.tagName)) {
                return
            }

            event.preventDefault()

            if (field.matches('[data-payment-desk-amount]') && field.value === '' && ! field.validity.badInput) {
                this.fillFullDebt(form)

                return
            }

            this.submitPayment(form)
        },

        fillFullDebt(form) {
            const amount = form.querySelector('[data-payment-desk-amount]')

            if (! amount?.dataset.paymentDeskFullDebt) {
                return
            }

            amount.value = amount.dataset.paymentDeskFullDebt
            amount.dispatchEvent(new Event('input', { bubbles: true }))
        },

        submitPayment(form) {
            form.querySelector('.fi-modal-footer [type=submit]')?.click()
        },
    }"
    x-on:keydown="if ($event.key === 'F1') { $event.preventDefault(); hotkeysOpen = ! hotkeysOpen }"
    x-on:payment-desk-reset.window="afterPayment($event.detail)"
    x-on:modal-closed.window="if ($event.target.closest?.('.fi-modal')?.querySelector('[data-payment-desk-pay]')) focusSearch()"
>
    <div class="fi-payment-desk flex flex-col gap-6">
        @include('filament.pages.payment-desk.hotkeys')

        <x-filament::section
            icon="heroicon-o-magnifying-glass"
            icon-color="primary"
            heading="Поиск абонента"
            :description="$hasBillingPeriod
                ? 'Расчётный месяц: ' . $this->billingPeriodLabel() . '. Лицевой счёт, фамилия или телефон.'
                : 'Лицевой счёт, фамилия или телефон.'"
        >
            {{-- What the list below was found for, read by Enter to tell a stale list from a fresh one. --}}
            <div
                id="payment-desk-search-state"
                data-payment-desk-term="{{ $term }}"
                data-payment-desk-billing-open="{{ $hasBillingPeriod ? '1' : '0' }}"
            >
                <input
                    id="payment-desk-search"
                    type="search"
                    autofocus
                    autocomplete="off"
                    wire:model.live.debounce.300ms="search"
                    x-on:input="activeRow = 0; notice = ''"
                    x-on:keydown="onSearchKeydown($event)"
                    placeholder="Лицевой счёт, фамилия или телефон"
                    aria-label="Поиск абонента"
                    aria-describedby="payment-desk-search-keys"
                    class="fi-input w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-base text-gray-950 shadow-sm outline-none transition selection:bg-amber-300 selection:text-gray-950 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-white/20 dark:bg-white/5 dark:text-white"
                >

                <p id="payment-desk-search-keys" class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-400">
                    <span>@include($chip, ['key' => '↓']) @include($chip, ['key' => '↑']) выбрать</span>
                    @if ($hasBillingPeriod)
                        <span>@include($chip, ['key' => 'Enter']) оплатить</span>
                    @else
                        <span>@include($chip, ['key' => 'Enter']) не принимает оплату: расчётный месяц не открыт</span>
                    @endif
                    <span>@include($chip, ['key' => '⌫']) стереть выделенный счёт</span>
                    <span>@include($chip, ['key' => 'Esc']) очистить</span>
                    <span>@include($chip, ['key' => 'F1']) все клавиши</span>
                </p>

                {{-- Client-only hints, announced by screen readers as they appear. --}}
                <div wire:ignore aria-live="polite">
                    <p
                        x-show="notice"
                        x-cloak
                        x-text="notice"
                        class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200"
                    ></p>

                    <p
                        x-show="afterPaymentHint"
                        x-cloak
                        class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-400/30 dark:bg-emerald-400/10 dark:text-emerald-200"
                    >
                        <span class="font-semibold" x-text="afterPaymentHint"></span>
                        выделен целиком: @include($chip, ['key' => '⌫']) стирает всё одним нажатием, или просто набирайте следующий счёт поверх.
                    </p>
                </div>

                @if ($this->isSearchTooShort())
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                        Введите минимум 2 символа: лицевой счёт, фамилию или телефон.
                    </p>
                @elseif (filled($term) && $results->isEmpty())
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                        Ничего не найдено. Проверьте лицевой счёт или попробуйте фамилию.
                    </p>
                @endif

                @if ($results->isNotEmpty())
                    {{-- A new search is a new list: the key renews it, and its highlight starts at the first row. --}}
                    <ul
                        id="payment-desk-results"
                        wire:key="payment-desk-results-{{ md5($term . '|' . implode(',', $results->modelKeys())) }}"
                        x-init="activeRow = 0"
                        class="mt-3 divide-y divide-gray-200 overflow-hidden rounded-xl border border-gray-200 dark:divide-white/10 dark:border-white/10"
                    >
                        @foreach ($results as $result)
                            @php $closing = $this->closingBalanceOf($result); @endphp
                            <li
                                wire:key="payment-desk-client-{{ $result->getKey() }}"
                                data-payment-desk-row="{{ $loop->index }}"
                                data-payment-desk-account="{{ $result->account_number }}"
                                x-bind:class="activeRow === {{ $loop->index }} && 'bg-amber-50 ring-2 ring-inset ring-primary-600 dark:bg-primary-400/10 dark:ring-primary-400'"
                                x-bind:aria-current="activeRow === {{ $loop->index }} ? 'true' : 'false'"
                                class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <span
                                        aria-hidden="true"
                                        x-bind:class="activeRow === {{ $loop->index }} ? 'text-primary-600 dark:text-primary-400' : 'text-transparent'"
                                        class="flex size-5 shrink-0 items-center justify-center"
                                    >
                                        <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::ChevronRight" class="size-4" />
                                    </span>

                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-gray-950 dark:text-white">
                                            {{ $result->account_number }} — {{ $result->name }}
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ $this->addressOf($result) }}
                                            @if (filled($result->phone)) · {{ $result->phone }} @endif
                                        </p>
                                    </div>
                                </div>

                                <div class="flex shrink-0 flex-wrap items-center gap-3">
                                    @if ($closing > 0)
                                        <x-filament::badge color="danger">Долг {{ $this->money($closing) }}</x-filament::badge>
                                    @elseif ($closing < 0)
                                        <x-filament::badge color="info">Переплата {{ $this->money(abs($closing)) }}</x-filament::badge>
                                    @else
                                        <x-filament::badge color="success">Долга нет</x-filament::badge>
                                    @endif

                                    @if ($this->offersPayment($result))
                                        {{ ($this->payAction)(['client' => $result->getKey()]) }}
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    @unless ($hasBillingPeriod)
                        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                            Расчётный месяц не открыт: приём оплат недоступен.
                        </p>
                    @endunless
                @endif
            </div>
        </x-filament::section>

        @if ($totals['count'] > 0)
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Оплат за сегодня</div>
                    <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ number_format($totals['count'], 0, ',', ' ') }}</div>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/50 dark:bg-emerald-950/20">
                    <div class="text-xs font-medium text-emerald-700 dark:text-emerald-300">Принято за сегодня</div>
                    <div class="mt-1 text-lg font-semibold text-emerald-700 dark:text-emerald-300">{{ $this->money($totals['amount']) }}</div>
                </div>
            </div>
        @endif

        {{ $this->table }}
    </div>
</x-filament-panels::page>
