<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Карточка абонента {{ $client->account_number }}</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="bg-stone-100 text-zinc-950 antialiased dark:bg-zinc-950 dark:text-zinc-50 print:bg-white print:text-zinc-950">
        <main class="mx-auto min-h-screen w-full max-w-5xl px-4 py-8 sm:px-6 lg:px-8 print:min-h-0 print:max-w-none print:p-0">
            <article class="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900 print:rounded-none print:border-0 print:shadow-none">
                <header class="bg-linear-to-br from-amber-100 via-white to-teal-100 px-6 py-6 dark:from-amber-950/50 dark:via-zinc-900 dark:to-teal-950/50 print:bg-white print:px-0 print:py-0">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.22em] text-teal-800 dark:text-teal-300 print:text-zinc-600">
                                Печатная карточка
                            </p>
                            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-zinc-950 dark:text-white print:text-2xl print:text-zinc-950">
                                Карточка абонента
                            </h1>
                            <p class="mt-3 max-w-2xl text-sm text-zinc-600 dark:text-zinc-300 print:text-zinc-700">
                                Данные абонента, адрес, настройки начисления, счётчики и оплаты.
                            </p>
                        </div>

                        <div class="rounded-2xl border border-white/80 bg-white/80 px-4 py-3 text-sm shadow-sm dark:border-zinc-800 dark:bg-zinc-900/80 print:border-zinc-300 print:bg-white print:shadow-none">
                            <p class="text-zinc-500 dark:text-zinc-400 print:text-zinc-600">Лицевой счёт</p>
                            <p class="mt-1 text-xl font-semibold text-zinc-950 dark:text-white print:text-zinc-950">{{ $client->account_number }}</p>
                            <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400 print:text-zinc-600">
                                Сформирована: {{ $generatedAt->format('d.m.Y H:i') }}
                            </p>
                        </div>
                    </div>
                </header>

                <div class="flex flex-col gap-6 px-6 py-6 print:px-0 print:py-5">
                    <section class="break-inside-avoid-page rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800 print:rounded-none print:border-zinc-300">
                        <h2 class="text-lg font-semibold">Данные абонента</h2>

                        <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            @foreach ($clientDetails as $detail)
                                <div class="break-inside-avoid rounded-xl bg-zinc-50 px-4 py-3 dark:bg-zinc-950 print:rounded-none print:border-b print:border-zinc-200 print:bg-white print:px-0">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400 print:text-zinc-600">{{ $detail['label'] }}</dt>
                                    <dd class="mt-1 text-sm font-medium text-zinc-950 dark:text-zinc-50 print:text-zinc-950">{{ $detail['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>

                    <section class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div class="break-inside-avoid-page rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800 print:rounded-none print:border-zinc-300">
                            <h2 class="text-lg font-semibold">Адрес</h2>

                            <dl class="mt-4 grid grid-cols-1 gap-3">
                                @foreach ($addressDetails as $detail)
                                    <div class="break-inside-avoid rounded-xl bg-zinc-50 px-4 py-3 dark:bg-zinc-950 print:rounded-none print:border-b print:border-zinc-200 print:bg-white print:px-0">
                                        <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400 print:text-zinc-600">{{ $detail['label'] }}</dt>
                                        <dd class="mt-1 text-sm font-medium text-zinc-950 dark:text-zinc-50 print:text-zinc-950">{{ $detail['value'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>

                        <div class="break-inside-avoid-page rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800 print:rounded-none print:border-zinc-300">
                            <h2 class="text-lg font-semibold">Настройки начисления</h2>

                            <dl class="mt-4 grid grid-cols-1 gap-3">
                                @foreach ($billingDetails as $detail)
                                    <div class="break-inside-avoid rounded-xl bg-zinc-50 px-4 py-3 dark:bg-zinc-950 print:rounded-none print:border-b print:border-zinc-200 print:bg-white print:px-0">
                                        <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400 print:text-zinc-600">{{ $detail['label'] }}</dt>
                                        <dd class="mt-1 text-sm font-medium text-zinc-950 dark:text-zinc-50 print:text-zinc-950">{{ $detail['value'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800 print:rounded-none print:border-zinc-300">
                        <h2 class="text-lg font-semibold">Счётчики</h2>

                        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2 print:grid-cols-1">
                            @forelse ($meters as $meterCard)
                                <article class="break-inside-avoid-page rounded-2xl bg-zinc-50 p-4 dark:bg-zinc-950 print:rounded-none print:border-b print:border-zinc-200 print:bg-white print:px-0">
                                    <h3 class="text-sm font-semibold text-zinc-950 dark:text-zinc-50 print:text-zinc-950">{{ $meterCard['title'] }}</h3>

                                    <dl class="mt-3 grid grid-cols-1 gap-2">
                                        @foreach ($meterCard['details'] as $detail)
                                            <div class="grid grid-cols-[10rem_1fr] gap-3 text-sm">
                                                <dt class="text-zinc-500 dark:text-zinc-400 print:text-zinc-600">{{ $detail['label'] }}</dt>
                                                <dd class="font-medium text-zinc-950 dark:text-zinc-50 print:text-zinc-950">{{ $detail['value'] }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </article>
                            @empty
                                <p class="rounded-xl border border-dashed border-zinc-300 px-4 py-6 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400 print:rounded-none">
                                    Нет счётчиков.
                                </p>
                            @endforelse
                        </div>
                    </section>

                    <section class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800 print:rounded-none print:border-zinc-300">
                        <h2 class="text-lg font-semibold">Оплаты</h2>

                        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2 print:grid-cols-1">
                            @forelse ($payments as $paymentCard)
                                <article class="break-inside-avoid-page rounded-2xl bg-zinc-50 p-4 dark:bg-zinc-950 print:rounded-none print:border-b print:border-zinc-200 print:bg-white print:px-0">
                                    <h3 class="text-sm font-semibold text-zinc-950 dark:text-zinc-50 print:text-zinc-950">{{ $paymentCard['title'] }}</h3>

                                    <dl class="mt-3 grid grid-cols-1 gap-2">
                                        @foreach ($paymentCard['details'] as $detail)
                                            <div class="grid grid-cols-[10rem_1fr] gap-3 text-sm">
                                                <dt class="text-zinc-500 dark:text-zinc-400 print:text-zinc-600">{{ $detail['label'] }}</dt>
                                                <dd class="font-medium text-zinc-950 dark:text-zinc-50 print:text-zinc-950">{{ $detail['value'] }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </article>
                            @empty
                                <p class="rounded-xl border border-dashed border-zinc-300 px-4 py-6 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400 print:rounded-none">
                                    Нет оплат.
                                </p>
                            @endforelse
                        </div>
                    </section>
                </div>
            </article>
        </main>
    </body>
</html>
