<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Design Preview</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="bg-zinc-50 text-zinc-950 antialiased dark:bg-zinc-950 dark:text-zinc-50">
        <main class="mx-auto flex min-h-screen w-full max-w-7xl flex-col gap-8 px-4 py-6 sm:px-6 lg:px-8">
            <header class="flex flex-col gap-4 border-b border-zinc-200 pb-6 dark:border-zinc-800 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-sm font-medium text-teal-700 dark:text-teal-300">Preview</p>
                    <h1 class="text-2xl font-semibold text-zinc-950 dark:text-white">Design Preview</h1>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button class="rounded-md border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-900">
                        Secondary
                    </button>
                    <button class="rounded-md bg-teal-700 px-3 py-2 text-sm font-medium text-white transition hover:bg-teal-800 dark:bg-teal-500 dark:text-zinc-950 dark:hover:bg-teal-400">
                        Primary
                    </button>
                </div>
            </header>

            <section class="grid grid-cols-1 gap-6 lg:grid-cols-[20rem_1fr]">
                <aside class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">Tenant</p>
                            <h2 class="text-base font-semibold">Организация</h2>
                        </div>
                        <span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">Active</span>
                    </div>

                    <div class="mt-4 flex flex-col gap-2">
                        <button class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-left text-sm font-medium text-amber-950 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-100">
                            ТОО Водоканал Алматы
                        </button>
                        <button class="rounded-md border border-zinc-200 px-3 py-2 text-left text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-950">
                            ИП Абонент-Сервис
                        </button>
                        <button class="mt-2 rounded-md bg-zinc-950 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-white">
                            Создать организацию
                        </button>
                    </div>
                </aside>

                <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex flex-col gap-3 border-b border-zinc-200 pb-4 dark:border-zinc-800 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">Filament tenancy</p>
                            <h2 class="text-base font-semibold">Профиль организации</h2>
                        </div>
                        <button class="rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-700 dark:bg-amber-400 dark:text-zinc-950 dark:hover:bg-amber-300">
                            Сохранить
                        </button>
                    </div>

                    <form class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <label class="flex flex-col gap-2 text-sm font-medium">
                            Название организации
                            <input class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50" value="ТОО Водоканал Алматы">
                        </label>

                        <label class="flex flex-col gap-2 text-sm font-medium">
                            БИН / ИИН
                            <input class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50" value="123456789012">
                        </label>

                        <label class="flex flex-col gap-2 text-sm font-medium">
                            Телефон
                            <input class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50" value="+7 777 000 00 00">
                        </label>

                        <label class="flex flex-col gap-2 text-sm font-medium">
                            IBAN
                            <input class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50" value="KZ86125KZT5004100100">
                        </label>

                        <label class="flex flex-col gap-2 text-sm font-medium md:col-span-2">
                            Адрес
                            <input class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50" value="Алматы, Абая 10">
                        </label>
                    </form>
                </div>
            </section>

            <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-col gap-4 border-b border-zinc-200 p-4 dark:border-zinc-800 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">Organization users</p>
                        <h2 class="text-base font-semibold">Пользователи организации</h2>
                        <p class="mt-2 max-w-2xl text-sm text-zinc-500 dark:text-zinc-400">
                            Роль хранится в привязке к организации. Назначение регионов и улиц доступно только для контроллера.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <button class="rounded-md border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-950">
                            Добавить пользователя
                        </button>
                        <button class="rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-amber-700 dark:bg-amber-400 dark:text-zinc-950 dark:hover:bg-amber-300">
                            Создать пользователя
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 p-4 xl:grid-cols-[1fr_22rem]">
                    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
                        <table class="w-full min-w-200 text-left text-sm">
                            <thead class="bg-zinc-100 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-950 dark:text-zinc-400">
                                <tr>
                                    <th class="px-4 py-3">Имя</th>
                                    <th class="px-4 py-3">Email</th>
                                    <th class="px-4 py-3">Роль</th>
                                    <th class="px-4 py-3">Регионы</th>
                                    <th class="px-4 py-3">Улицы</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                <tr>
                                    <td class="px-4 py-3 font-medium">Алия Оператор</td>
                                    <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">operator@example.com</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full bg-sky-100 px-2 py-1 text-xs font-medium text-sky-800 dark:bg-sky-900/40 dark:text-sky-200">Оператор</span>
                                    </td>
                                    <td class="px-4 py-3 text-zinc-400 dark:text-zinc-600">-</td>
                                    <td class="px-4 py-3 text-zinc-400 dark:text-zinc-600">-</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 font-medium">Ержан Контроллер</td>
                                    <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">controller@example.com</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">Контроллер</span>
                                    </td>
                                    <td class="px-4 py-3">Алмалинский</td>
                                    <td class="px-4 py-3">Бостандыкский / Сатпаева</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/60 dark:bg-emerald-950/20">
                        <p class="text-sm font-medium text-emerald-900 dark:text-emerald-100">Форма доступа контроллера</p>
                        <div class="mt-4 flex flex-col gap-3">
                            <label class="flex flex-col gap-2 text-sm font-medium">
                                Роль
                                <select class="h-10 rounded-md border border-emerald-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-emerald-800 dark:bg-zinc-950 dark:text-zinc-50">
                                    <option>Контроллер</option>
                                </select>
                            </label>
                            <label class="flex flex-col gap-2 text-sm font-medium">
                                Регионы контроллера
                                <select multiple class="min-h-24 rounded-md border border-emerald-300 bg-white px-3 py-2 text-sm font-normal text-zinc-950 dark:border-emerald-800 dark:bg-zinc-950 dark:text-zinc-50">
                                    <option selected>Алмалинский</option>
                                    <option>Медеуский</option>
                                </select>
                            </label>
                            <label class="flex flex-col gap-2 text-sm font-medium">
                                Отдельные улицы контроллера
                                <select multiple class="min-h-24 rounded-md border border-emerald-300 bg-white px-3 py-2 text-sm font-normal text-zinc-950 dark:border-emerald-800 dark:bg-zinc-950 dark:text-zinc-50">
                                    <option selected>Бостандыкский / Сатпаева</option>
                                    <option>Наурызбайский / Жандосова</option>
                                </select>
                            </label>
                        </div>

                        <div class="mt-5 rounded-lg border border-emerald-200 bg-white p-3 dark:border-emerald-900/70 dark:bg-zinc-950">
                            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Режим контроллера</p>
                            <div class="mt-3 grid grid-cols-1 gap-2 text-sm">
                                <div class="flex items-center justify-between gap-3 rounded-md bg-zinc-50 px-3 py-2 dark:bg-zinc-900">
                                    <span>Абоненты и счётчики</span>
                                    <span class="rounded-full bg-zinc-200 px-2 py-1 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">только просмотр</span>
                                </div>
                                <div class="flex items-center justify-between gap-3 rounded-md bg-emerald-100 px-3 py-2 dark:bg-emerald-950/40">
                                    <span>Показания</span>
                                    <span class="rounded-full bg-emerald-600 px-2 py-1 text-xs font-medium text-white dark:bg-emerald-400 dark:text-zinc-950">ввод и изменение</span>
                                </div>
                                <div class="flex items-center justify-between gap-3 rounded-md bg-rose-50 px-3 py-2 dark:bg-rose-950/30">
                                    <span>Оплаты, тарифы, профиль</span>
                                    <span class="rounded-full bg-rose-100 px-2 py-1 text-xs font-medium text-rose-700 dark:bg-rose-900/60 dark:text-rose-200">скрыто</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-col gap-3 border-b border-zinc-200 pb-4 dark:border-zinc-800 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">ClientResource</p>
                        <h2 class="text-base font-semibold">Данные абонента</h2>
                    </div>
                    <span class="rounded-full bg-teal-100 px-2 py-1 text-xs font-medium text-teal-800 dark:bg-teal-900/40 dark:text-teal-200">
                        Лицевой счёт readonly
                    </span>
                </div>

                <form class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <label class="flex flex-col gap-2 text-sm font-medium">
                        Лицевой счёт
                        <input readonly class="h-10 rounded-md border border-zinc-300 bg-zinc-100 px-3 text-sm font-normal text-zinc-600 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-400" value="100001">
                    </label>

                    <label class="flex flex-col gap-2 text-sm font-medium">
                        ФИО / Наименование *
                        <input class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50" value="Иванов Иван">
                    </label>

                    <label class="flex flex-col gap-2 text-sm font-medium">
                        ИИН *
                        <input class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50" value="870101300123">
                    </label>

                    <label class="flex flex-col gap-2 text-sm font-medium">
                        Тип клиента *
                        <select class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50">
                            <option>Физ. лицо</option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-2 text-sm font-medium">
                        Количество проживающих *
                        <input type="number" min="1" class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50" value="1">
                    </label>

                    <label class="flex flex-col gap-2 text-sm font-medium">
                        Статус *
                        <select class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50">
                            <option>Активный</option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-2 text-sm font-medium">
                        Телефон *
                        <input class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50" value="+7 777 111 22 33">
                    </label>

                    <label class="flex flex-col gap-2 text-sm font-medium">
                        Договор *
                        <input class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50" value="Договор №15">
                    </label>

                    <label class="flex flex-col gap-2 text-sm font-medium">
                        Тех. условия
                        <input class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50" value="ТУ-2026-15">
                    </label>
                </form>
            </section>

            <section class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Total</p>
                    <p class="mt-2 text-2xl font-semibold">1,284</p>
                    <p class="mt-2 text-sm text-emerald-700 dark:text-emerald-300">+12.4%</p>
                </div>
                <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Pending</p>
                    <p class="mt-2 text-2xl font-semibold">37</p>
                    <p class="mt-2 text-sm text-amber-700 dark:text-amber-300">Needs review</p>
                </div>
                <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Errors</p>
                    <p class="mt-2 text-2xl font-semibold">4</p>
                    <p class="mt-2 text-sm text-red-700 dark:text-red-300">Action required</p>
                </div>
            </section>

            <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-col gap-4 border-b border-zinc-200 p-4 dark:border-zinc-800 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">Reports / XLSX export</p>
                        <h2 class="text-base font-semibold">Ведомость снятия показаний</h2>
                        <p class="mt-2 max-w-2xl text-sm text-zinc-500 dark:text-zinc-400">
                            Header actions страницы отчёта: возврат к списку и скачивание Excel-файла.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <button class="rounded-md border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-950">
                            Все отчёты
                        </button>
                        <button class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-medium text-white transition hover:bg-emerald-800 dark:bg-emerald-500 dark:text-zinc-950 dark:hover:bg-emerald-400">
                            Скачать Excel
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-240 text-left text-sm">
                        <thead class="bg-zinc-100 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-950 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-3">Лицевой счёт</th>
                                <th class="px-4 py-3">ФИО</th>
                                <th class="px-4 py-3">Адрес</th>
                                <th class="px-4 py-3">Прож.</th>
                                <th class="px-4 py-3">Счётчик</th>
                                <th class="px-4 py-3">Дата установки</th>
                                <th class="px-4 py-3">Предыдущее</th>
                                <th class="px-4 py-3">Показание</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            <tr>
                                <td class="px-4 py-3 font-medium">100001</td>
                                <td class="px-4 py-3">Иванов Иван</td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">Алмалинский, Абая, д. 10, кв. 5</td>
                                <td class="px-4 py-3">3</td>
                                <td class="px-4 py-3 font-medium">MTR-001</td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">15.01.2024</td>
                                <td class="px-4 py-3 font-medium">21.7500</td>
                                <td class="px-4 py-3 text-zinc-400 dark:text-zinc-600">пусто</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_24rem]">
                <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex flex-col gap-3 border-b border-zinc-200 p-4 dark:border-zinc-800 sm:flex-row sm:items-center sm:justify-between">
                        <h2 class="text-base font-semibold">Table State</h2>
                        <input class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm text-zinc-950 placeholder:text-zinc-400 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50" placeholder="Filter">
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-160 text-left text-sm">
                            <thead class="bg-zinc-100 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-950 dark:text-zinc-400">
                                <tr>
                                    <th class="px-4 py-3">Name</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">Updated</th>
                                    <th class="px-4 py-3 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                <tr>
                                    <td class="px-4 py-3 font-medium">Record alpha</td>
                                    <td class="px-4 py-3"><span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">Active</span></td>
                                    <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">Today</td>
                                    <td class="px-4 py-3 text-right font-medium">42,000</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 font-medium">Record beta</td>
                                    <td class="px-4 py-3"><span class="rounded-full bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Pending</span></td>
                                    <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">Yesterday</td>
                                    <td class="px-4 py-3 text-right font-medium">18,500</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 font-medium">Record gamma</td>
                                    <td class="px-4 py-3"><span class="rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-800 dark:bg-red-900/40 dark:text-red-200">Failed</span></td>
                                    <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">May 20</td>
                                    <td class="px-4 py-3 text-right font-medium">7,100</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <h2 class="text-base font-semibold">Form State</h2>

                    <form class="mt-4 flex flex-col gap-4">
                        <label class="flex flex-col gap-2 text-sm font-medium">
                            Title
                            <input class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50" value="Example title">
                        </label>

                        <label class="flex flex-col gap-2 text-sm font-medium">
                            Status
                            <select class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50">
                                <option>Active</option>
                                <option>Pending</option>
                                <option>Disabled</option>
                            </select>
                        </label>

                        <label class="flex items-center gap-3 text-sm font-medium">
                            <input type="checkbox" class="size-4 rounded border-zinc-300 text-teal-700" checked>
                            Enabled
                        </label>
                    </form>
                </div>
            </section>

            <section class="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="bg-linear-to-br from-amber-100 via-white to-teal-100 p-6 dark:from-amber-950/50 dark:via-zinc-900 dark:to-teal-950/50">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.22em] text-teal-800 dark:text-teal-300">Печатная карточка</p>
                            <h2 class="mt-2 text-3xl font-semibold tracking-tight">Карточка абонента</h2>
                            <p class="mt-3 max-w-2xl text-sm text-zinc-600 dark:text-zinc-300">
                                Preview Blade-страницы, которая открывается из действия «Карточка».
                            </p>
                        </div>

                        <div class="rounded-2xl border border-white/80 bg-white/80 px-4 py-3 text-sm shadow-sm dark:border-zinc-800 dark:bg-zinc-900/80">
                            <p class="text-zinc-500 dark:text-zinc-400">Лицевой счёт</p>
                            <p class="mt-1 text-xl font-semibold">100010</p>
                            <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">Сформирована: 04.06.2026 10:15</p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 p-6 lg:grid-cols-2">
                    <div class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800">
                        <h3 class="text-lg font-semibold">Данные абонента</h3>
                        <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div class="rounded-xl bg-zinc-50 px-4 py-3 dark:bg-zinc-950">
                                <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">ФИО / Наименование</dt>
                                <dd class="mt-1 text-sm font-medium">Иванов Иван</dd>
                            </div>
                            <div class="rounded-xl bg-zinc-50 px-4 py-3 dark:bg-zinc-950">
                                <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Тип начисления</dt>
                                <dd class="mt-1 text-sm font-medium">По счётчику</dd>
                            </div>
                            <div class="rounded-xl bg-zinc-50 px-4 py-3 dark:bg-zinc-950">
                                <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Адрес</dt>
                                <dd class="mt-1 text-sm font-medium">Алмалинский район, Абая, д. 10, кв. 15</dd>
                            </div>
                            <div class="rounded-xl bg-zinc-50 px-4 py-3 dark:bg-zinc-950">
                                <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Статус</dt>
                                <dd class="mt-1 text-sm font-medium">Активный</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="grid grid-cols-1 gap-4">
                        <div class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800">
                            <h3 class="text-lg font-semibold">Счётчики</h3>
                            <div class="mt-4 rounded-2xl bg-zinc-50 p-4 dark:bg-zinc-950">
                                <p class="text-sm font-semibold">Счётчик #1</p>
                                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">MTR-100010 · начальное показание 15.2500</p>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800">
                            <h3 class="text-lg font-semibold">Оплаты</h3>
                            <div class="mt-4 rounded-2xl bg-zinc-50 p-4 dark:bg-zinc-950">
                                <p class="text-sm font-semibold">Оплата #1</p>
                                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">202605 · 2 500.00 KZT</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-col gap-3 border-b border-zinc-200 p-4 dark:border-zinc-800 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">BillingPeriodResource</p>
                        <h2 class="text-base font-semibold">Расчётные месяцы</h2>
                        <p class="mt-2 max-w-2xl text-sm text-zinc-500 dark:text-zinc-400">
                            Новый месяц открывается автоматически следующим по очереди после закрытия текущего.
                        </p>
                    </div>
                    <button class="rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-700 dark:bg-amber-400 dark:text-zinc-950 dark:hover:bg-amber-300">
                        Новый расчётный месяц
                    </button>
                </div>

                <div class="grid grid-cols-1 gap-4 p-4 xl:grid-cols-[1fr_22rem]">
                    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
                        <table class="w-full min-w-180 text-left text-sm">
                            <thead class="bg-zinc-100 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-950 dark:text-zinc-400">
                                <tr>
                                    <th class="px-4 py-3">Месяц</th>
                                    <th class="px-4 py-3">Статус</th>
                                    <th class="px-4 py-3">Создано</th>
                                    <th class="px-4 py-3">Ошибок</th>
                                    <th class="px-4 py-3">Закрыт</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                <tr>
                                    <td class="px-4 py-3 font-medium">05.2026</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">Открыт</span>
                                    </td>
                                    <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">0</td>
                                    <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">0</td>
                                    <td class="px-4 py-3 text-zinc-400 dark:text-zinc-600">-</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 font-medium">04.2026</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full bg-zinc-200 px-2 py-1 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">Закрыт</span>
                                    </td>
                                    <td class="px-4 py-3">128</td>
                                    <td class="px-4 py-3">0</td>
                                    <td class="px-4 py-3">30.04.2026 18:20</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 font-medium">03.2026</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full bg-rose-100 px-2 py-1 text-xs font-medium text-rose-700 dark:bg-rose-900/60 dark:text-rose-200">Ошибка закрытия</span>
                                    </td>
                                    <td class="px-4 py-3">124</td>
                                    <td class="px-4 py-3">4</td>
                                    <td class="px-4 py-3 text-zinc-400 dark:text-zinc-600">-</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/60 dark:bg-amber-950/20">
                        <p class="text-sm font-medium text-amber-950 dark:text-amber-100">Текущий месяц</p>
                        <div class="mt-4 rounded-md border border-amber-300 bg-white p-3 dark:border-amber-800 dark:bg-zinc-950">
                            <p class="text-xs font-medium uppercase tracking-wide text-amber-700 dark:text-amber-300">Будет закрыт</p>
                            <p class="mt-1 text-2xl font-semibold text-zinc-950 dark:text-zinc-50">05.2026</p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Статус: открыт</p>
                        </div>
                        <button class="mt-4 w-full rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-700 dark:bg-amber-400 dark:text-zinc-950 dark:hover:bg-amber-300">
                            Закрыть месяц
                        </button>
                        <p class="mt-3 text-xs text-amber-800 dark:text-amber-200">
                            Оплаты, показания и корректировки автоматически относятся к 05.2026. Пока он открыт, система не позволит открыть 06.2026.
                        </p>
                    </div>
                </div>
            </section>

            <section class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="rounded-lg border border-dashed border-zinc-300 bg-white p-6 text-center dark:border-zinc-700 dark:bg-zinc-900">
                    <h2 class="text-base font-semibold">Empty State</h2>
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">No records match the current filters.</p>
                </div>
                <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                    <h2 class="text-base font-semibold">Loading State</h2>
                    <div class="mt-4 flex flex-col gap-3">
                        <div class="h-3 rounded bg-zinc-200 dark:bg-zinc-800"></div>
                        <div class="h-3 w-2/3 rounded bg-zinc-200 dark:bg-zinc-800"></div>
                        <div class="h-3 w-5/6 rounded bg-zinc-200 dark:bg-zinc-800"></div>
                    </div>
                </div>
                <div class="rounded-lg border border-red-200 bg-red-50 p-6 text-red-950 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-100">
                    <h2 class="text-base font-semibold">Error State</h2>
                    <p class="mt-2 text-sm text-red-800 dark:text-red-200">The request could not be completed.</p>
                </div>
            </section>
        </main>
    </body>
</html>
