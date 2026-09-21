            @if ($printFailed)
                <style>
                    .receipt-a4-page,
                    .receipt-bulk-print-button {
                        display: none !important;
                    }
                </style>

                <section class="receipt-bulk-print-error rounded-2xl border border-dashed border-rose-300 bg-rose-50 p-8 text-center shadow-sm print:hidden">
                    <h2 class="text-xl font-semibold tracking-tight text-rose-950">Печать прервана</h2>
                    <p class="mt-2 text-sm text-rose-900">
                        Не удалось сформировать все квитанции, поэтому листы скрыты и не печатаются.
                        Обновите страницу, чтобы сформировать печать заново; если ошибка повторится, сузьте фильтры или обратитесь к администратору.
                    </p>
                </section>
            @elseif ($limitExceeded)
                <section class="rounded-2xl border border-dashed border-amber-300 bg-amber-50 p-8 text-center shadow-sm print:hidden">
                    <h2 class="text-xl font-semibold tracking-tight text-amber-950">Слишком много квитанций для одной печати</h2>
                    <p class="mt-2 text-sm text-amber-900">
                        Выбрано {{ number_format($receiptsCount, thousands_separator: ' ') }} квитанций, а за один раз можно напечатать не больше {{ number_format($printLimit, thousands_separator: ' ') }}.
                        Сузьте фильтры — выберите регион, улицу или контроллера — или отметьте меньше квитанций и напечатайте их частями.
                    </p>
                </section>
            @elseif ($printPagesCount === 0)
                <section class="rounded-2xl border border-dashed border-zinc-300 bg-white p-8 text-center shadow-sm print:hidden">
                    <h2 class="text-xl font-semibold tracking-tight">Нет квитанций для печати</h2>
                    <p class="mt-2 text-sm text-zinc-600">
                        По фильтру «{{ $periodLabel }}» ещё нет сформированных квитанций.
                    </p>
                </section>
            @endif
        </main>

        @if ($printPagesCount > 0 && ! $printFailed)
            <script>
                window.addEventListener('load', () => {
                    window.print();
                });
            </script>
        @endif
    </body>
</html>
