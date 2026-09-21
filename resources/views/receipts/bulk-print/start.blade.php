<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Массовая печать квитанций {{ $periodLabel }}</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

        <style>{!! $templateCss !!}</style>
    </head>
    <body class="bg-stone-100 text-zinc-950 antialiased print:bg-white">
        <main class="receipt-print-main mx-auto min-h-screen w-full max-w-5xl px-4 py-6 sm:px-6 lg:px-8 print:min-h-0 print:max-w-none print:p-0">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 print:hidden">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.22em] text-teal-800">PDF</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight">Массовая печать квитанций</h1>
                    <p class="mt-1 text-sm text-zinc-600">
                        @if ($limitExceeded)
                            Фильтр: {{ $periodLabel }}. Квитанций: {{ number_format($receiptsCount, thousands_separator: ' ') }}.
                        @else
                            Фильтр: {{ $periodLabel }}. Квитанций: {{ $receiptsCount }}. Листов A4: {{ $printPagesCount }}, до {{ \App\Http\Controllers\ReceiptPrintController::BULK_COPIES_PER_A4_PAGE }} экземпляров на листе.
                        @endif
                    </p>
                </div>

                @if ($printPagesCount > 0)
                    <button
                        type="button"
                        onclick="window.print()"
                        class="receipt-bulk-print-button rounded-md bg-zinc-950 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-800"
                    >
                        Печатать PDF
                    </button>
                @endif
            </div>
