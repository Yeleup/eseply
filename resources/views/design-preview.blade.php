<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Design Preview</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

        <style>
            .billing-period-required-callout {
                margin-top: 1rem;
            }
        </style>
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

                <div class="billing-period-required-callout rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900/60 dark:bg-red-950/20">
                    <p class="text-sm font-semibold text-red-900 dark:text-red-100">Расчётный месяц не открыт</p>
                    <p class="mt-1 text-sm text-red-800 dark:text-red-200">
                        Откройте расчётный месяц в разделе «Расчётные месяцы», чтобы вводить оплаты, показания, корректировки и закрывать месяц.
                    </p>
                </div>

                <div class="mt-3 rounded-xl border border-sky-200 bg-sky-50 p-4 dark:border-sky-900/60 dark:bg-sky-950/20">
                    <p class="text-sm font-semibold text-sky-900 dark:text-sky-100">Расчётный месяц закрывается</p>
                    <p class="mt-1 text-sm text-sky-800 dark:text-sky-200">
                        Идёт расчёт начислений по всем активным абонентам. Уведомление о результате придёт, когда расчёт закончится.
                    </p>
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

                    <label class="flex flex-col gap-2 text-sm font-medium">
                        Город *
                        <select class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50">
                            <option>Алматы</option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-2 text-sm font-medium">
                        Регион *
                        <select class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50">
                            <option>Алмалинский</option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-2 text-sm font-medium">
                        Улица *
                        <select class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50">
                            <option>Абая</option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-2 text-sm font-medium">
                        Дом
                        <input class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50" value="10">
                    </label>

                    <label class="flex flex-col gap-2 text-sm font-medium">
                        Квартира / помещение
                        <input class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-normal text-zinc-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50" value="15">
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
                <div class="flex flex-col gap-4 border-b border-zinc-200 p-4 dark:border-zinc-800 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">Dashboard / стартовая страница панели</p>
                        <h2 class="text-base font-semibold">Дашборд</h2>
                        <p class="mt-2 max-w-2xl text-sm text-zinc-500 dark:text-zinc-400">
                            Картина организации за один расчётный месяц: абоненты, снятие показаний, потребление, начисления, оплаты, долг, динамика по месяцам, прогресс контроллеров и срез по районам.
                        </p>
                    </div>

                    <label class="flex flex-col gap-1 text-sm sm:w-64">
                        <span class="font-medium text-zinc-700 dark:text-zinc-200">Расчётный месяц</span>
                        <select class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100">
                            <option>08.2026 — Открыт</option>
                            <option>07.2026 — Закрыт</option>
                            <option>06.2026 — Закрыт</option>
                        </select>
                    </label>
                </div>

                <div class="grid grid-cols-1 gap-4 border-b border-zinc-200 p-4 dark:border-zinc-800 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950">
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">Абоненты</p>
                        <p class="mt-2 text-2xl font-semibold">1 240</p>
                        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">всего 1 310 · новых за месяц 24</p>
                    </div>
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950">
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">Счётчики</p>
                        <p class="mt-2 text-2xl font-semibold">1 118</p>
                        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">по счётчику 1 118</p>
                    </div>
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950">
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">Снято показаний</p>
                        <p class="mt-2 text-2xl font-semibold text-amber-700 dark:text-amber-300">86.4 %</p>
                        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">966 из 1 118</p>
                    </div>
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950">
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">Потребление</p>
                        <p class="mt-2 text-2xl font-semibold">18 402</p>
                        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">за выбранный месяц, м³</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 border-b border-zinc-200 p-4 dark:border-zinc-800 sm:grid-cols-2 xl:grid-cols-3">
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950">
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">Начислено</p>
                        <p class="mt-2 text-2xl font-semibold text-amber-700 dark:text-amber-300">12 480 000 ₸</p>
                        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">предварительно, по 966 квитанциям</p>
                    </div>
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950">
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">Оплачено</p>
                        <p class="mt-2 text-2xl font-semibold text-emerald-700 dark:text-emerald-300">9 120 000 ₸</p>
                        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">412 оплат · сбор 73.1 %</p>
                    </div>
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950">
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">Долг на конец месяца</p>
                        <p class="mt-2 text-2xl font-semibold text-red-700 dark:text-red-300">3 360 000 ₸</p>
                        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">318 абонентов</p>
                    </div>
                </div>

                <div class="border-b border-zinc-200 p-4 dark:border-zinc-800">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold">Начисления и оплаты по месяцам</h3>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Последние 12 расчётных месяцев организации.</p>
                        </div>

                        <div class="flex items-center gap-4 text-sm text-zinc-600 dark:text-zinc-300">
                            <span class="flex items-center gap-2">
                                <span class="inline-block size-3 rounded-sm bg-amber-500"></span>
                                Начислено
                            </span>
                            <span class="flex items-center gap-2">
                                <span class="inline-block size-3 rounded-sm bg-emerald-500"></span>
                                Оплачено
                            </span>
                        </div>
                    </div>

                    <div class="mt-4 flex h-64 items-end gap-2 rounded-lg border border-dashed border-zinc-300 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-950">
                        @foreach ([[45, 32], [52, 40], [61, 47], [58, 44], [66, 51], [72, 58], [69, 55], [74, 60], [80, 63], [77, 61], [85, 68], [90, 66]] as $bar)
                            <div class="flex h-full flex-1 items-end gap-1">
                                <div class="w-full rounded-t bg-amber-500" style="height: {{ $bar[0] }}%"></div>
                                <div class="w-full rounded-t bg-emerald-500" style="height: {{ $bar[1] }}%"></div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 border-b border-zinc-200 p-4 dark:border-zinc-800 lg:grid-cols-2">
                    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-800">
                        <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-950">
                            <h3 class="text-sm font-semibold">Прогресс снятия по контроллерам</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[36rem] text-left text-sm">
                                <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-950 dark:text-zinc-400">
                                    <tr>
                                        <th class="px-4 py-2 font-medium">Контроллер</th>
                                        <th class="px-4 py-2 font-medium">Всего</th>
                                        <th class="px-4 py-2 font-medium">Снято</th>
                                        <th class="px-4 py-2 font-medium">Не снято</th>
                                        <th class="px-4 py-2 font-medium">Процент</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                    <tr>
                                        <td class="px-4 py-2">Абаев Абай</td>
                                        <td class="px-4 py-2">412</td>
                                        <td class="px-4 py-2">268</td>
                                        <td class="px-4 py-2">144</td>
                                        <td class="px-4 py-2">
                                            <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-500/15 dark:text-red-300">65 %</span>
                                        </td>
                                    </tr>
                                    <tr class="bg-zinc-50 dark:bg-zinc-950">
                                        <td class="px-4 py-2">Букеев Букей</td>
                                        <td class="px-4 py-2">356</td>
                                        <td class="px-4 py-2">311</td>
                                        <td class="px-4 py-2">45</td>
                                        <td class="px-4 py-2">
                                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">87.4 %</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-2">Валиев Валихан</td>
                                        <td class="px-4 py-2">350</td>
                                        <td class="px-4 py-2">350</td>
                                        <td class="px-4 py-2">0</td>
                                        <td class="px-4 py-2">
                                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300">100 %</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-800">
                        <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-950">
                            <h3 class="text-sm font-semibold">Срез по районам</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[42rem] text-left text-sm">
                                <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-950 dark:text-zinc-400">
                                    <tr>
                                        <th class="px-4 py-2 font-medium">Район</th>
                                        <th class="px-4 py-2 font-medium">Абонентов</th>
                                        <th class="px-4 py-2 font-medium">Снято</th>
                                        <th class="px-4 py-2 font-medium">Начислено</th>
                                        <th class="px-4 py-2 font-medium">Оплачено</th>
                                        <th class="px-4 py-2 font-medium">Долг</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                    <tr>
                                        <td class="px-4 py-2">Алматы / Бостандыкский</td>
                                        <td class="px-4 py-2">512</td>
                                        <td class="px-4 py-2">74.2 %</td>
                                        <td class="px-4 py-2">5 120 000 ₸</td>
                                        <td class="px-4 py-2">3 480 000 ₸</td>
                                        <td class="px-4 py-2 text-red-700 dark:text-red-300">1 640 000 ₸</td>
                                    </tr>
                                    <tr class="bg-zinc-50 dark:bg-zinc-950">
                                        <td class="px-4 py-2">Алматы / Алмалинский</td>
                                        <td class="px-4 py-2">438</td>
                                        <td class="px-4 py-2">91.6 %</td>
                                        <td class="px-4 py-2">4 380 000 ₸</td>
                                        <td class="px-4 py-2">3 260 000 ₸</td>
                                        <td class="px-4 py-2 text-red-700 dark:text-red-300">1 120 000 ₸</td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-2">Алматы / Медеуский</td>
                                        <td class="px-4 py-2">290</td>
                                        <td class="px-4 py-2">96.8 %</td>
                                        <td class="px-4 py-2">2 980 000 ₸</td>
                                        <td class="px-4 py-2">2 380 000 ₸</td>
                                        <td class="px-4 py-2 text-red-700 dark:text-red-300">600 000 ₸</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 p-4 lg:grid-cols-2">
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Контроллер: денежные блоки скрыты</p>

                        <div class="mt-3 grid grid-cols-2 gap-3">
                            <div class="rounded-md border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-950">
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">Абоненты</p>
                                <p class="mt-1 text-lg font-semibold">412</p>
                            </div>
                            <div class="rounded-md border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-950">
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">Счётчики</p>
                                <p class="mt-1 text-lg font-semibold">412</p>
                            </div>
                            <div class="rounded-md border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-950">
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">Снято показаний</p>
                                <p class="mt-1 text-lg font-semibold text-red-700 dark:text-red-300">65 %</p>
                            </div>
                            <div class="rounded-md border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-950">
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">Потребление</p>
                                <p class="mt-1 text-lg font-semibold">6 140</p>
                            </div>
                        </div>

                        <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
                            В прогрессе снятия контроллер видит одну строку — свою.
                        </p>
                    </div>

                    <div class="rounded-lg border border-dashed border-zinc-300 p-6 text-center dark:border-zinc-700">
                        <p class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Расчётный месяц не открыт</p>
                        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                            Откройте расчётный месяц в разделе «Расчётные месяцы», чтобы увидеть показатели.
                        </p>
                    </div>
                </div>
            </section>

            <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-col gap-4 border-b border-zinc-200 p-4 dark:border-zinc-800 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">Reports / XLSX export</p>
                        <h2 class="text-base font-semibold">Отчёты учёта</h2>
                        <p class="mt-2 max-w-2xl text-sm text-zinc-500 dark:text-zinc-400">
                            Список отчётов показывает показания, оплаты, неоплаченные квитанции, установку/замену счётчиков, долги, потребления и новые лицевые счета. На странице отчёта доступны возврат к списку и скачивание Excel-файла.
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

                <div class="grid grid-cols-1 gap-3 border-b border-zinc-200 p-4 dark:border-zinc-800 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950">
                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Отчёт</p>
                        <h3 class="mt-2 font-semibold">Ведомость снятия показаний</h3>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Форма обхода по активным счётчикам.</p>
                    </div>

                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/60 dark:bg-amber-950/30">
                        <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">Новый отчёт</p>
                        <h3 class="mt-2 font-semibold">Список не снятых показаний</h3>
                        <p class="mt-1 text-sm text-amber-800/80 dark:text-amber-200/80">Счётчики без показания за текущий расчётный месяц.</p>
                    </div>

                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/60 dark:bg-emerald-950/30">
                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Новый отчёт</p>
                        <h3 class="mt-2 font-semibold">Процент снятия по контроллерам</h3>
                        <p class="mt-1 text-sm text-emerald-800/80 dark:text-emerald-200/80">Снято, не снято и процент по зонам ответственности.</p>
                    </div>

                    <div class="rounded-lg border border-sky-200 bg-sky-50 p-4 dark:border-sky-900/60 dark:bg-sky-950/30">
                        <p class="text-xs font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-300">Новый отчёт</p>
                        <h3 class="mt-2 font-semibold">Новые лицевые счета</h3>
                        <p class="mt-1 text-sm text-sky-800/80 dark:text-sky-200/80">Абоненты, созданные в текущем расчётном месяце.</p>
                    </div>

                    <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900/60 dark:bg-blue-950/30">
                        <p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">Новый отчёт</p>
                        <h3 class="mt-2 font-semibold">Отчёт по оплатам</h3>
                        <p class="mt-1 text-sm text-blue-800/80 dark:text-blue-200/80">Оплаты за текущий расчётный месяц.</p>
                    </div>

                    <div class="rounded-lg border border-rose-200 bg-rose-50 p-4 dark:border-rose-900/60 dark:bg-rose-950/30">
                        <p class="text-xs font-semibold uppercase tracking-wide text-rose-700 dark:text-rose-300">Новый отчёт</p>
                        <h3 class="mt-2 font-semibold">Отчёт по неоплаченным</h3>
                        <p class="mt-1 text-sm text-rose-800/80 dark:text-rose-200/80">Квитанции, где начислено больше оплаченного.</p>
                    </div>

                    <div class="rounded-lg border border-cyan-200 bg-cyan-50 p-4 dark:border-cyan-900/60 dark:bg-cyan-950/30">
                        <p class="text-xs font-semibold uppercase tracking-wide text-cyan-700 dark:text-cyan-300">Новый отчёт</p>
                        <h3 class="mt-2 font-semibold">Замена/установка счётчика</h3>
                        <p class="mt-1 text-sm text-cyan-800/80 dark:text-cyan-200/80">Установленные и снятые счётчики периода.</p>
                    </div>

                    <div class="rounded-lg border border-orange-200 bg-orange-50 p-4 dark:border-orange-900/60 dark:bg-orange-950/30">
                        <p class="text-xs font-semibold uppercase tracking-wide text-orange-700 dark:text-orange-300">Новый отчёт</p>
                        <h3 class="mt-2 font-semibold">Отчёт по долгам</h3>
                        <p class="mt-1 text-sm text-orange-800/80 dark:text-orange-200/80">Положительное конечное сальдо по квитанциям.</p>
                    </div>

                    <div class="rounded-lg border border-lime-200 bg-lime-50 p-4 dark:border-lime-900/60 dark:bg-lime-950/30">
                        <p class="text-xs font-semibold uppercase tracking-wide text-lime-700 dark:text-lime-300">Новый отчёт</p>
                        <h3 class="mt-2 font-semibold">Отчёт по потреблениям</h3>
                        <p class="mt-1 text-sm text-lime-800/80 dark:text-lime-200/80">Потребление по показаниям счётчиков.</p>
                    </div>
                </div>

                <div class="border-b border-zinc-200 p-4 dark:border-zinc-800">
                    <div>
                        <p class="text-sm font-medium text-violet-700 dark:text-violet-300">Фильтры по датам</p>
                        <h3 class="mt-1 text-base font-semibold">Диапазон «с» / «по» для каждой колонки даты</h3>
                        <p class="mt-1 max-w-3xl text-sm text-zinc-500 dark:text-zinc-400">
                            Каждая колонка даты детального отчёта фильтруется диапазоном с включительными границами. Активные границы отображаются индикаторами над таблицей и снимаются по отдельности. Сводный режим фильтры не учитывает; XLSX-выгрузка не учитывает их везде, кроме ведомости снятия показаний.
                        </p>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950">
                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Панель фильтра</p>
                            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="text-sm font-medium">Дата оплаты с</label>
                                    <div class="mt-1 flex items-center justify-between rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                                        <span>01.06.2026</span>
                                        <span aria-hidden="true" class="text-zinc-400">📅</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="text-sm font-medium">Дата оплаты по</label>
                                    <div class="mt-1 flex items-center justify-between rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-400 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-500">
                                        <span>дд.мм.гггг</span>
                                        <span aria-hidden="true" class="text-zinc-400">📅</span>
                                    </div>
                                </div>
                            </div>
                            <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">Каждую границу можно указать отдельно. Строки без даты скрываются, пока фильтр активен.</p>
                        </div>

                        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950">
                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Индикаторы активного фильтра</p>
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-1 rounded-full bg-violet-100 px-3 py-1 text-xs font-medium text-violet-800 dark:bg-violet-950/60 dark:text-violet-200">
                                    Дата оплаты: с 01.06.2026
                                    <button type="button" class="text-violet-500 transition hover:text-violet-700 dark:hover:text-violet-100">&times;</button>
                                </span>
                                <span class="inline-flex items-center gap-1 rounded-full bg-violet-100 px-3 py-1 text-xs font-medium text-violet-800 dark:bg-violet-950/60 dark:text-violet-200">
                                    Дата оплаты: по 15.06.2026
                                    <button type="button" class="text-violet-500 transition hover:text-violet-700 dark:hover:text-violet-100">&times;</button>
                                </span>
                            </div>
                            <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                                Фильтры есть у дат: оплата, снятие показания, установка и снятие счётчика, создание абонента, формирование квитанции. «Замена/установка счётчика» имеет два независимых фильтра, которые работают одновременно.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="border-b border-zinc-200 p-4 dark:border-zinc-800">
                    <div>
                        <p class="text-sm font-medium text-violet-700 dark:text-violet-300">Фильтры ведомости снятия показаний</p>
                        <h3 class="mt-1 text-base font-semibold">Адрес каскадом и зоны контроллеров</h3>
                        <p class="mt-1 max-w-3xl text-sm text-zinc-500 dark:text-zinc-400">
                            Ведомость печатают для обхода участка, поэтому у неё есть фильтры по городу, району и улицам абонента и по контроллерам. Город сужает список районов, район — список улиц. Улиц и контроллеров можно выбрать несколько.
                        </p>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950">
                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Панель фильтра</p>
                            <div class="mt-3 flex flex-col gap-3">
                                <div>
                                    <label class="text-sm font-medium">Город</label>
                                    <div class="mt-1 flex items-center justify-between rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                                        <span>Алматы</span>
                                        <span aria-hidden="true" class="text-zinc-400">▾</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="text-sm font-medium">Район</label>
                                    <div class="mt-1 flex items-center justify-between rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                                        <span>Алмалинский</span>
                                        <span aria-hidden="true" class="text-zinc-400">▾</span>
                                    </div>
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Без выбранного города список показывает все районы как «Город / Район».</p>
                                </div>
                                <div>
                                    <label class="text-sm font-medium">Улицы</label>
                                    <div class="mt-1 flex flex-wrap items-center gap-2 rounded-md border border-zinc-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900">
                                        <span class="inline-flex items-center gap-1 rounded bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                            Абая
                                            <button type="button" class="text-zinc-400 transition hover:text-zinc-700 dark:hover:text-zinc-100">&times;</button>
                                        </span>
                                        <span class="inline-flex items-center gap-1 rounded bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                            Гоголя
                                            <button type="button" class="text-zinc-400 transition hover:text-zinc-700 dark:hover:text-zinc-100">&times;</button>
                                        </span>
                                        <span class="text-sm text-zinc-400 dark:text-zinc-500">Выберите улицы…</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="text-sm font-medium">Контроллеры</label>
                                    <div class="mt-1 flex flex-wrap items-center gap-2 rounded-md border border-zinc-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900">
                                        <span class="inline-flex items-center gap-1 rounded bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                            Иванов И. (ivanov@example.kz)
                                            <button type="button" class="text-zinc-400 transition hover:text-zinc-700 dark:hover:text-zinc-100">&times;</button>
                                        </span>
                                        <span class="text-sm text-zinc-400 dark:text-zinc-500">Выберите контроллеров…</span>
                                    </div>
                                </div>
                            </div>
                            <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">Смена города очищает район и улицы, смена района очищает улицы. Любое поле можно заполнить отдельно от остальных.</p>
                        </div>

                        <div class="flex flex-col gap-4">
                            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950">
                                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Индикаторы активного фильтра</p>
                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center gap-1 rounded-full bg-violet-100 px-3 py-1 text-xs font-medium text-violet-800 dark:bg-violet-950/60 dark:text-violet-200">
                                        Город: Алматы
                                        <button type="button" class="text-violet-500 transition hover:text-violet-700 dark:hover:text-violet-100">&times;</button>
                                    </span>
                                    <span class="inline-flex items-center gap-1 rounded-full bg-violet-100 px-3 py-1 text-xs font-medium text-violet-800 dark:bg-violet-950/60 dark:text-violet-200">
                                        Район: Алмалинский
                                        <button type="button" class="text-violet-500 transition hover:text-violet-700 dark:hover:text-violet-100">&times;</button>
                                    </span>
                                    <span class="inline-flex items-center gap-1 rounded-full bg-violet-100 px-3 py-1 text-xs font-medium text-violet-800 dark:bg-violet-950/60 dark:text-violet-200">
                                        Улицы: Абая, Гоголя
                                        <button type="button" class="text-violet-500 transition hover:text-violet-700 dark:hover:text-violet-100">&times;</button>
                                    </span>
                                    <span class="inline-flex items-center gap-1 rounded-full bg-violet-100 px-3 py-1 text-xs font-medium text-violet-800 dark:bg-violet-950/60 dark:text-violet-200">
                                        Контроллеры: Иванов И. (ivanov@example.kz)
                                        <button type="button" class="text-violet-500 transition hover:text-violet-700 dark:hover:text-violet-100">&times;</button>
                                    </span>
                                </div>
                                <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">Город, район и улицы снимаются по отдельности, хотя относятся к одному фильтру адреса.</p>
                            </div>

                            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/60 dark:bg-emerald-950/30">
                                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">XLSX по фильтру</p>
                                <p class="mt-2 text-sm text-emerald-800/80 dark:text-emerald-200/80">
                                    «Скачать Excel» выгружает те же строки, что видны на экране с теми же фильтрами: выгрузка ведомости повторяет применённые фильтры, включая дату установки. Отложенные значения, которые ещё не применены кнопкой, в выгрузку не попадают. Поиск по таблице на выгрузку по-прежнему не влияет.
                                </p>
                                <p class="mt-2 text-sm text-emerald-800/80 dark:text-emerald-200/80">
                                    Строка остаётся при фильтре по контроллерам, если абонент попадает в зону хотя бы одного выбранного контроллера. Контроллер видит только свою зону, поэтому выбор коллеги не расширяет список.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border-b border-zinc-200 p-4 dark:border-zinc-800">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                        <div>
                            <p class="text-sm font-medium text-indigo-700 dark:text-indigo-300">Сводный режим</p>
                            <h3 class="mt-1 text-base font-semibold">Отчёт по оплатам: по контроллерам</h3>
                            <p class="mt-1 max-w-3xl text-sm text-zinc-500 dark:text-zinc-400">
                                Сводка использует те же строки отчёта, но группирует их по контроллерам, районам или улицам. Если абонент подходит нескольким контроллерам, он отображается в строке каждого подходящего контроллера.
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button class="rounded-md border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-950">
                                Детально
                            </button>
                            <button class="rounded-md bg-indigo-700 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-800 dark:bg-indigo-400 dark:text-zinc-950 dark:hover:bg-indigo-300">
                                По контроллерам
                            </button>
                            <button class="rounded-md border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-950">
                                По районам
                            </button>
                            <button class="rounded-md border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-950">
                                По улицам
                            </button>
                        </div>
                    </div>

                    <div class="mt-4 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
                        <table class="w-full min-w-200 text-left text-sm">
                            <thead class="bg-indigo-50 text-xs font-semibold uppercase text-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-200">
                                <tr>
                                    <th class="px-4 py-3">Контроллер</th>
                                    <th class="px-4 py-3">Абонентов</th>
                                    <th class="px-4 py-3">Строк</th>
                                    <th class="px-4 py-3">Оплат</th>
                                    <th class="px-4 py-3">Сумма оплат</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                                <tr>
                                    <td class="px-4 py-3 font-medium">Controller By Region</td>
                                    <td class="px-4 py-3">1</td>
                                    <td class="px-4 py-3">1</td>
                                    <td class="px-4 py-3">1</td>
                                    <td class="px-4 py-3 font-semibold text-emerald-700 dark:text-emerald-300">3 500.00 KZT</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 font-medium">Controller By Street</td>
                                    <td class="px-4 py-3">1</td>
                                    <td class="px-4 py-3">1</td>
                                    <td class="px-4 py-3">1</td>
                                    <td class="px-4 py-3 font-semibold text-emerald-700 dark:text-emerald-300">3 500.00 KZT</td>
                                </tr>
                            </tbody>
                        </table>
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
                                <th class="px-4 py-3">Период</th>
                                <th class="px-4 py-3">Предыдущее</th>
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
                                <td class="px-4 py-3">06.2026</td>
                                <td class="px-4 py-3 font-medium">22</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 font-medium">100001</td>
                                <td class="px-4 py-3">Иванов Иван</td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">Алмалинский, Абая, д. 10, кв. 5</td>
                                <td class="px-4 py-3">3</td>
                                <td class="px-4 py-3 font-medium">MTR-002</td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">-</td>
                                <td class="px-4 py-3">06.2026</td>
                                <td class="px-4 py-3 font-medium">20</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                    <h3 class="text-sm font-semibold">Процент снятия по контроллерам</h3>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Отдельная таблица показывает прогресс за текущий расчётный месяц.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-220 text-left text-sm">
                        <thead class="bg-zinc-100 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-950 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-3">Контроллер</th>
                                <th class="px-4 py-3">Регионы</th>
                                <th class="px-4 py-3">Улицы</th>
                                <th class="px-4 py-3">Период</th>
                                <th class="px-4 py-3">Всего</th>
                                <th class="px-4 py-3">Снято</th>
                                <th class="px-4 py-3">Не снято</th>
                                <th class="px-4 py-3">%</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            <tr>
                                <td class="px-4 py-3 font-medium">Controller A</td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">Алмалинский</td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">-</td>
                                <td class="px-4 py-3">06.2026</td>
                                <td class="px-4 py-3">3</td>
                                <td class="px-4 py-3">2</td>
                                <td class="px-4 py-3">1</td>
                                <td class="px-4 py-3"><span class="rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-900/50 dark:text-amber-200">66.67%</span></td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 font-medium">Controller B</td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">-</td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">Медеуский / Достык</td>
                                <td class="px-4 py-3">06.2026</td>
                                <td class="px-4 py-3">1</td>
                                <td class="px-4 py-3">1</td>
                                <td class="px-4 py-3">0</td>
                                <td class="px-4 py-3"><span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200">100.00%</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                    <h3 class="text-sm font-semibold">Новые лицевые счета</h3>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Таблица показывает абонентов, созданных в текущем расчётном месяце, и использует тот же XLSX-набор колонок.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-260 text-left text-sm">
                        <thead class="bg-zinc-100 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-950 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-3">Лицевой счёт</th>
                                <th class="px-4 py-3">ФИО</th>
                                <th class="px-4 py-3">Адрес</th>
                                <th class="px-4 py-3">Тип</th>
                                <th class="px-4 py-3">Начисление</th>
                                <th class="px-4 py-3">Статус</th>
                                <th class="px-4 py-3">Прож.</th>
                                <th class="px-4 py-3">Телефон</th>
                                <th class="px-4 py-3">Период</th>
                                <th class="px-4 py-3">Создан</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            <tr>
                                <td class="px-4 py-3 font-medium">400001</td>
                                <td class="px-4 py-3">Новый абонент</td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">Наурызбайский, Жандосова, д. 7, кв. 21</td>
                                <td class="px-4 py-3">Физ. лицо</td>
                                <td class="px-4 py-3">На одного человека</td>
                                <td class="px-4 py-3"><span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200">Активный</span></td>
                                <td class="px-4 py-3">4</td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">+7 701 000 00 01</td>
                                <td class="px-4 py-3">06.2026</td>
                                <td class="px-4 py-3">05.06.2026 09:15</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 font-medium">400002</td>
                                <td class="px-4 py-3">Закрытый новый счёт</td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">-</td>
                                <td class="px-4 py-3">Коммерческие объекты</td>
                                <td class="px-4 py-3">Фиксированная сумма</td>
                                <td class="px-4 py-3"><span class="rounded-full bg-zinc-200 px-2 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">Неактивный</span></td>
                                <td class="px-4 py-3">1</td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">-</td>
                                <td class="px-4 py-3">06.2026</td>
                                <td class="px-4 py-3">16.06.2026 18:30</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                    <h3 class="text-sm font-semibold">Финансовые отчёты</h3>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Отчёты по оплатам, неоплаченным квитанциям и долгам используют текущий расчётный месяц и один XLSX-паттерн.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-240 text-left text-sm">
                        <thead class="bg-zinc-100 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-950 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-3">Отчёт</th>
                                <th class="px-4 py-3">Лицевой счёт</th>
                                <th class="px-4 py-3">Абонент</th>
                                <th class="px-4 py-3">Период</th>
                                <th class="px-4 py-3">Начислено</th>
                                <th class="px-4 py-3">Оплачено</th>
                                <th class="px-4 py-3">Остаток</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            <tr>
                                <td class="px-4 py-3 font-medium">Оплаты</td>
                                <td class="px-4 py-3">500001</td>
                                <td class="px-4 py-3">Плательщик</td>
                                <td class="px-4 py-3">06.2026</td>
                                <td class="px-4 py-3 text-zinc-400">-</td>
                                <td class="px-4 py-3 font-semibold text-emerald-700 dark:text-emerald-300">3 500.00 KZT</td>
                                <td class="px-4 py-3 text-zinc-400">-</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 font-medium">Неоплаченные</td>
                                <td class="px-4 py-3">510001</td>
                                <td class="px-4 py-3">Неоплаченный абонент</td>
                                <td class="px-4 py-3">06.2026</td>
                                <td class="px-4 py-3">6 000.00 KZT</td>
                                <td class="px-4 py-3">2 000.00 KZT</td>
                                <td class="px-4 py-3 font-semibold text-rose-700 dark:text-rose-300">4 000.00 KZT</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 font-medium">Долги</td>
                                <td class="px-4 py-3">510002</td>
                                <td class="px-4 py-3">Абонент с долгом</td>
                                <td class="px-4 py-3">06.2026</td>
                                <td class="px-4 py-3">1 000.00 KZT</td>
                                <td class="px-4 py-3">1 000.00 KZT</td>
                                <td class="px-4 py-3 font-semibold text-orange-700 dark:text-orange-300">2 500.00 KZT</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                    <h3 class="text-sm font-semibold">Счётчики и потребления</h3>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Операционный блок показывает установку/снятие счётчиков и потребление по введённым показаниям.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-240 text-left text-sm">
                        <thead class="bg-zinc-100 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-950 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-3">Отчёт</th>
                                <th class="px-4 py-3">Лицевой счёт</th>
                                <th class="px-4 py-3">Счётчик</th>
                                <th class="px-4 py-3">Операция / период</th>
                                <th class="px-4 py-3">Предыдущее</th>
                                <th class="px-4 py-3">Текущее</th>
                                <th class="px-4 py-3">Потребление</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            <tr>
                                <td class="px-4 py-3 font-medium">Замена/установка</td>
                                <td class="px-4 py-3">520001</td>
                                <td class="px-4 py-3 font-medium">MTR-INSTALL</td>
                                <td class="px-4 py-3"><span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200">Установка</span></td>
                                <td class="px-4 py-3 text-zinc-400">-</td>
                                <td class="px-4 py-3 text-zinc-400">-</td>
                                <td class="px-4 py-3 text-zinc-400">-</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 font-medium">Потребления</td>
                                <td class="px-4 py-3">530001</td>
                                <td class="px-4 py-3 font-medium">MTR-CONSUME</td>
                                <td class="px-4 py-3">06.2026</td>
                                <td class="px-4 py-3">10</td>
                                <td class="px-4 py-3">26</td>
                                <td class="px-4 py-3 font-semibold text-lime-700 dark:text-lime-300">16</td>
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
                <div class="flex flex-col gap-4 border-b border-zinc-200 bg-linear-to-br from-amber-100 via-white to-teal-100 p-6 dark:border-zinc-800 dark:from-amber-950/50 dark:via-zinc-900 dark:to-teal-950/50 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.22em] text-teal-800 dark:text-teal-300">Печатная карточка</p>
                        <h2 class="mt-2 text-3xl font-semibold tracking-tight">Карточка абонента A4</h2>
                        <p class="mt-3 max-w-3xl text-sm text-zinc-600 dark:text-zinc-300">
                            Preview Blade-страницы с кнопкой «Печатать PDF». В печати блоки сжимаются до плотных A4-таблиц: данные абонента, адрес, начисление, счётчики, показания, оплаты, корректировки, начисления и квитанции.
                        </p>
                    </div>

                    <button class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-white">
                        Печатать PDF
                    </button>
                </div>

                <div class="grid gap-5 p-6">
                    <div class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-teal-800 dark:text-teal-300">Карточка абонента</p>
                                <h3 class="mt-1 text-2xl font-semibold tracking-tight">ТОО Водоканал · Иванов Иван</h3>
                            </div>
                            <dl class="grid grid-cols-[6rem_1fr] gap-x-2 gap-y-1 rounded-xl bg-zinc-50 px-4 py-3 text-sm dark:bg-zinc-950">
                                <dt class="text-zinc-500 dark:text-zinc-400">Лицевой счёт</dt>
                                <dd class="font-semibold">100010</dd>
                                <dt class="text-zinc-500 dark:text-zinc-400">Сформирована</dt>
                                <dd class="font-semibold">19.06.2026 10:15</dd>
                            </dl>
                        </div>

                        <dl class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="rounded-xl bg-zinc-50 px-4 py-3 dark:bg-zinc-950">
                                <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Тип клиента</dt>
                                <dd class="mt-1 text-sm font-medium">Физ. лицо</dd>
                            </div>
                            <div class="rounded-xl bg-zinc-50 px-4 py-3 dark:bg-zinc-950">
                                <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Адрес</dt>
                                <dd class="mt-1 text-sm font-medium">Алмалинский, Абая, 10/15</dd>
                            </div>
                            <div class="rounded-xl bg-zinc-50 px-4 py-3 dark:bg-zinc-950">
                                <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Тип начисления</dt>
                                <dd class="mt-1 text-sm font-medium">По счётчику</dd>
                            </div>
                            <div class="rounded-xl bg-zinc-50 px-4 py-3 dark:bg-zinc-950">
                                <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Статус</dt>
                                <dd class="mt-1 text-sm font-medium">Активный</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800">
                            <h3 class="text-lg font-semibold">Счётчики и показания</h3>
                            <div class="mt-4 overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-800">
                                <table class="w-full min-w-[34rem] text-left text-xs">
                                    <thead class="bg-zinc-100 text-[11px] uppercase tracking-wide text-zinc-600 dark:bg-zinc-950 dark:text-zinc-300">
                                        <tr>
                                            <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800">Период</th>
                                            <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800">Счётчик</th>
                                            <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800">Текущее</th>
                                            <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800">Расход</th>
                                            <th class="border-b border-zinc-200 px-3 py-2 dark:border-zinc-800">Фото</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr class="odd:bg-zinc-50/70 dark:odd:bg-zinc-950/60">
                                            <td class="border-r border-zinc-200 px-3 py-2 font-semibold dark:border-zinc-800">05.2026</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800">MTR-100010</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800">48</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right font-semibold dark:border-zinc-800">12</td>
                                            <td class="px-3 py-2">
                                                <span class="inline-flex h-8 w-10 items-center justify-center rounded border border-zinc-300 bg-zinc-100 text-[10px] font-medium text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">Фото</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800">
                            <h3 class="text-lg font-semibold">Оплаты и корректировки</h3>
                            <div class="mt-4 overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-800">
                                <table class="w-full min-w-[36rem] text-left text-xs">
                                    <thead class="bg-zinc-100 text-[11px] uppercase tracking-wide text-zinc-600 dark:bg-zinc-950 dark:text-zinc-300">
                                        <tr>
                                            <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800">Раздел</th>
                                            <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800">Период</th>
                                            <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800">Способ</th>
                                            <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800">Сумма</th>
                                            <th class="border-b border-zinc-200 px-3 py-2 dark:border-zinc-800">Дата</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr class="odd:bg-zinc-50/70 dark:odd:bg-zinc-950/60">
                                            <td class="border-r border-zinc-200 px-3 py-2 font-semibold dark:border-zinc-800">Оплата</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800">05.2026</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800">Наличные</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right font-semibold dark:border-zinc-800">2 500.00 KZT</td>
                                            <td class="px-3 py-2">26.05.2026</td>
                                        </tr>
                                        <tr>
                                            <td class="border-r border-zinc-200 px-3 py-2 font-semibold dark:border-zinc-800">Корректировка</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800">05.2026</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800">-</td>
                                            <td class="border-r border-zinc-200 px-3 py-2 text-right font-semibold dark:border-zinc-800">-500.00 KZT</td>
                                            <td class="px-3 py-2">27.05.2026</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-4 rounded-2xl border border-red-200 bg-red-50 p-4 dark:border-red-900/60 dark:bg-red-950/20">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-red-700 dark:text-red-300">Kaspi удалённая оплата</p>
                                        <p class="mt-1 text-sm font-medium text-red-950 dark:text-red-100">Заявка отправлена клиенту</p>
                                    </div>
                                    <span class="rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-900 dark:bg-amber-900/50 dark:text-amber-100">pending</span>
                                </div>
                                <dl class="mt-4 grid grid-cols-2 gap-3 text-xs">
                                    <div>
                                        <dt class="text-zinc-500 dark:text-zinc-400">Сумма</dt>
                                        <dd class="mt-1 font-semibold">2 500.00 KZT</dd>
                                    </div>
                                    <div>
                                        <dt class="text-zinc-500 dark:text-zinc-400">Телефон</dt>
                                        <dd class="mt-1 font-semibold text-red-700 dark:text-red-300">+7 700 123 45 67</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800">
                        <h3 class="text-lg font-semibold">Начисления и квитанции</h3>
                        <div class="mt-4 overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-800">
                            <table class="w-full min-w-[58rem] text-left text-xs">
                                <thead class="bg-zinc-100 text-[11px] uppercase tracking-wide text-zinc-600 dark:bg-zinc-950 dark:text-zinc-300">
                                    <tr>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800">Документ</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 dark:border-zinc-800">Период</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800">Начислено</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800">Оплачено</th>
                                        <th class="border-b border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800">Кон. сальдо</th>
                                        <th class="border-b border-zinc-200 px-3 py-2 dark:border-zinc-800">Дата</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="odd:bg-zinc-50/70 dark:odd:bg-zinc-950/60">
                                        <td class="border-r border-zinc-200 px-3 py-2 font-semibold dark:border-zinc-800">Квитанция 202605-100010</td>
                                        <td class="border-r border-zinc-200 px-3 py-2 dark:border-zinc-800">05.2026</td>
                                        <td class="border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800">6 435.00 KZT</td>
                                        <td class="border-r border-zinc-200 px-3 py-2 text-right dark:border-zinc-800">2 500.00 KZT</td>
                                        <td class="border-r border-zinc-200 px-3 py-2 text-right font-semibold dark:border-zinc-800">3 435.00 KZT</td>
                                        <td class="px-3 py-2">31.05.2026</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-4 text-center text-zinc-500" colspan="6">Пустое состояние: «Нет квитанций.»</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <section class="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-col gap-4 border-b border-zinc-200 p-6 dark:border-zinc-800 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.22em] text-teal-800 dark:text-teal-300">ReceiptResource</p>
                        <h2 class="mt-2 text-3xl font-semibold tracking-tight">Печать квитанций A5</h2>
                        <p class="mt-3 max-w-2xl text-sm text-zinc-600 dark:text-zinc-300">
                            Раздел «Квитанции» доступен в навигации. Один лист A5 содержит два компактных экземпляра: для организации и для абонента. Bulk-печать в таблице квитанций использует тот же шаблон для выбранных строк.
                        </p>
                    </div>

                    <button class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-white">
                        Печатать PDF
                    </button>
                </div>

                <div class="grid gap-4 border-b border-zinc-200 p-6 dark:border-zinc-800 lg:grid-cols-[minmax(0,1fr)_18rem]">
                    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-amber-700 dark:text-amber-300">Bulk action</p>
                        <h3 class="mt-2 text-lg font-semibold tracking-tight">Печатать выбранные / по фильтру</h3>
                        <p class="mt-2 text-sm leading-6 text-zinc-500 dark:text-zinc-400">
                            Оператор фильтрует таблицу квитанций по периоду, региону, улице или контроллеру, печатает все строки текущего фильтра или выбирает конкретные строки для bulk-печати без перерасчёта.
                        </p>

                        <div class="mt-5 grid gap-2">
                            <label class="text-sm font-medium">Текущий фильтр</label>
                            <div class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-zinc-700 dark:bg-zinc-950">
                                05.2026 · Регион Север · ул. Абая · 24 квитанции
                            </div>
                        </div>

                        <button class="mt-5 rounded-lg bg-zinc-950 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-white">
                            Печатать по фильтру
                        </button>
                    </div>

                    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                        <h3 class="font-semibold">Состояния</h3>
                        <ul class="mt-3 list-disc space-y-2 pl-5">
                            <li>Выбран любой фильтр: появляется «Печатать по фильтру».</li>
                            <li>Выбран контроллер: печатаются квитанции абонентов из его зоны ответственности.</li>
                            <li>Выбраны квитанции: доступно «Печатать выбранные».</li>
                            <li>В строке таблицы нет стандартного просмотра, только действие «Печать».</li>
                            <li>Нет квитанций: показывается пустое состояние.</li>
                            <li>Чужая квитанция: доступ закрыт tenant-проверкой.</li>
                        </ul>
                    </div>
                </div>

                <div class="bg-stone-100 p-6 dark:bg-zinc-950">
                    <div class="receipt-sheet">
                        @foreach (['Для организации', 'Для абонента'] as $copyTitle)
                            <article class="receipt-copy flex flex-col rounded-xl border border-zinc-900 bg-white p-4 text-[10px] leading-tight text-zinc-950 shadow-sm dark:border-zinc-200 dark:bg-white">
                                <header class="grid grid-cols-[minmax(0,1fr)_10.5rem] gap-3 border-b border-zinc-900 pb-2">
                                    <div>
                                        <p class="text-[9px] font-bold uppercase tracking-[0.18em] text-zinc-500">{{ $copyTitle }}</p>
                                        <p class="mt-1 text-[9px] font-semibold uppercase tracking-[0.12em] text-zinc-500">Квитанция на оплату коммунальной услуги</p>
                                        <h3 class="mt-1 text-base font-bold tracking-tight">ТОО Водоканал</h3>
                                        <p class="mt-0.5 text-[10px] text-zinc-600">Алматы, Абая 10</p>
                                    </div>

                                    <dl class="grid grid-cols-[3.4rem_1fr] gap-x-1 gap-y-1 text-[10px]">
                                        <dt class="text-zinc-500">Номер</dt>
                                        <dd class="font-semibold">202605-100010</dd>
                                        <dt class="text-zinc-500">Период</dt>
                                        <dd class="font-semibold">05.2026</dd>
                                        <dt class="text-zinc-500">Дата</dt>
                                        <dd class="font-semibold">14.06.2026</dd>
                                    </dl>
                                </header>

                                <div class="grid grid-cols-2 gap-3 border-b border-zinc-300 py-2">
                                    <section>
                                        <h4 class="text-[10px] font-bold uppercase tracking-wide">Абонент</h4>
                                        <dl class="mt-1 grid grid-cols-[4.8rem_1fr] gap-x-1 gap-y-0.5 text-[9px]">
                                            <dt class="text-zinc-500">Лицевой счёт</dt>
                                            <dd class="font-semibold">100010</dd>
                                            <dt class="text-zinc-500">Абонент</dt>
                                            <dd class="font-semibold">Иванов Иван</dd>
                                            <dt class="text-zinc-500">Адрес</dt>
                                            <dd class="font-semibold">Алматы, Абая 10</dd>
                                            <dt class="text-zinc-500">Услуга</dt>
                                            <dd class="font-semibold">Водоснабжение</dd>
                                        </dl>
                                    </section>

                                    <section>
                                        <h4 class="text-[10px] font-bold uppercase tracking-wide">Реквизиты</h4>
                                        <dl class="mt-1 grid grid-cols-[3.6rem_1fr] gap-x-1 gap-y-0.5 text-[9px]">
                                            <dt class="text-zinc-500">БИН / ИИН</dt>
                                            <dd class="font-semibold">123456789012</dd>
                                            <dt class="text-zinc-500">Телефон</dt>
                                            <dd class="font-semibold">+7 777 000 00 00</dd>
                                            <dt class="text-zinc-500">IBAN</dt>
                                            <dd class="font-semibold">KZ86125KZT5004100100</dd>
                                        </dl>
                                    </section>
                                </div>

                                <section class="mt-2">
                                    <div class="flex items-center justify-between gap-2">
                                        <h4 class="text-[10px] font-bold uppercase tracking-wide">Счётчики</h4>
                                        <p class="text-[8px] text-zinc-500">Сформирована: 14.06.2026 10:00</p>
                                    </div>

                                    <div class="mt-1 overflow-hidden rounded-lg border border-zinc-900">
                                        <table class="w-full text-left text-[9px]">
                                            <thead class="bg-zinc-100 uppercase tracking-wide text-zinc-600">
                                                <tr>
                                                    <th class="border-b border-r border-zinc-900 px-2 py-1.5">№ счётчика</th>
                                                    <th class="border-b border-r border-zinc-900 px-2 py-1.5 text-right">Предыдущее</th>
                                                    <th class="border-b border-r border-zinc-900 px-2 py-1.5 text-right">Текущее</th>
                                                    <th class="border-b border-r border-zinc-900 px-2 py-1.5 text-right">Расход</th>
                                                    <th class="border-b border-r border-zinc-900 px-2 py-1.5 text-right">Тариф</th>
                                                    <th class="border-b border-zinc-900 px-2 py-1.5 text-right">Сумма</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td class="border-r border-zinc-900 px-2 py-1.5 font-semibold">MTR-100010</td>
                                                    <td class="border-r border-zinc-900 px-2 py-1.5 text-right">100</td>
                                                    <td class="border-r border-zinc-900 px-2 py-1.5 text-right">120</td>
                                                    <td class="border-r border-zinc-900 px-2 py-1.5 text-right">20</td>
                                                    <td class="border-r border-zinc-900 px-2 py-1.5 text-right">90.00 KZT</td>
                                                    <td class="px-2 py-1.5 text-right font-bold">1 800.00 KZT</td>
                                                </tr>
                                                <tr class="bg-zinc-50 font-bold">
                                                    <td class="border-t border-r border-zinc-900 px-2 py-1.5" colspan="3">Итого</td>
                                                    <td class="border-t border-r border-zinc-900 px-2 py-1.5 text-right">20</td>
                                                    <td class="border-t border-r border-zinc-900 px-2 py-1.5"></td>
                                                    <td class="border-t border-zinc-900 px-2 py-1.5 text-right">1 800.00 KZT</td>
                                                </tr>
                                                <tr>
                                                    <td class="border-t border-r border-zinc-900 px-2 py-1 font-semibold" colspan="5">Долг</td>
                                                    <td class="border-t border-zinc-900 px-2 py-1 text-right font-semibold">0.00 KZT</td>
                                                </tr>
                                                <tr>
                                                    <td class="border-t border-r border-zinc-900 px-2 py-1 font-semibold" colspan="5">Оплачено</td>
                                                    <td class="border-t border-zinc-900 px-2 py-1 text-right font-semibold">0.00 KZT</td>
                                                </tr>
                                                <tr class="bg-zinc-50 font-bold">
                                                    <td class="border-t border-r border-zinc-900 px-2 py-1.5" colspan="5">К оплате</td>
                                                    <td class="border-t border-zinc-900 px-2 py-1.5 text-right">1 800.00 KZT</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </section>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-col gap-4 border-b border-zinc-200 p-6 dark:border-zinc-800 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.22em] text-emerald-800 dark:text-emerald-300">ReceiptTemplatePage</p>
                        <h2 class="mt-2 text-3xl font-semibold tracking-tight">Конструктор шаблона квитанции</h2>
                        <p class="mt-3 max-w-2xl text-sm text-zinc-600 dark:text-zinc-300">
                            Страница «Шаблон квитанции» в панели организации: слева блоки с перетаскиванием и тумблерами,
                            тексты, логотип и QR-код, внешний вид; справа живой предпросмотр. Печать применяет шаблон:
                            свой порядок блоков, выключенные блоки, свои тексты, один или два экземпляра на листе.
                        </p>
                    </div>
                </div>

                <div class="grid gap-4 border-b border-zinc-200 p-6 dark:border-zinc-800 lg:grid-cols-2">
                    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-700 dark:text-emerald-300">Стандартный шаблон</p>
                        <h3 class="mt-2 text-lg font-semibold tracking-tight">По умолчанию</h3>
                        <p class="mt-2 text-sm leading-6 text-zinc-500 dark:text-zinc-400">
                            Все блоки в стандартном порядке: шапка, абонент и реквизиты, счётчики, итоги.
                            Два экземпляра на листе. Используется, пока организация не настроила свой шаблон.
                        </p>
                    </div>

                    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-700 dark:text-emerald-300">Настроенный шаблон</p>
                        <h3 class="mt-2 text-lg font-semibold tracking-tight">С параметрами организации</h3>
                        <p class="mt-2 text-sm leading-6 text-zinc-500 dark:text-zinc-400">
                            Пример настроек: реквизиты выше абонента, таблица счётчиков выключена, свой заголовок
                            «Счёт за воду», примечание «Оплатите до 25 числа», логотип в шапке, QR-код в примечании,
                            один экземпляр на листе, крупный шрифт без рамок.
                        </p>
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

                        <div class="mt-4 border-t border-amber-300 pt-4 dark:border-amber-800">
                            <p class="text-xs font-medium uppercase tracking-wide text-amber-700 dark:text-amber-300">Пока идёт расчёт</p>
                            <button disabled title="Расчётный месяц закрывается. Дождитесь результата." class="mt-2 w-full cursor-not-allowed rounded-md bg-zinc-200 px-3 py-2 text-sm font-medium text-zinc-500 dark:bg-white/10 dark:text-zinc-400">
                                Закрыть месяц
                            </button>
                            <p class="mt-2 text-xs text-amber-800 dark:text-amber-200">
                                Статус: закрывается. Кнопка недоступна, пока очередь считает начисления.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white shadow-sm ring-1 ring-zinc-950/5 dark:border-white/10 dark:bg-zinc-900 dark:ring-white/10">
                <div class="flex flex-col gap-3 border-b border-zinc-200 p-5 dark:border-white/10 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm text-sky-700 dark:text-sky-300">Уведомления</p>
                        <h2 class="text-base font-semibold">Результат закрытия месяца</h2>
                        <p class="mt-2 max-w-2xl text-sm text-zinc-500 dark:text-zinc-400">
                            Закрытие месяца считается в очереди, поэтому результат приходит операторам организации как уведомление в базе данных.
                        </p>
                    </div>
                    <span class="flex w-fit items-center gap-2 rounded-md bg-sky-100 px-2 py-1 text-xs font-medium text-sky-700 ring-1 ring-sky-600/10 dark:bg-sky-400/10 dark:text-sky-300 dark:ring-sky-400/20">
                        Колокольчик
                        <span class="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-sky-600 px-1 text-[10px] font-semibold text-white dark:bg-sky-400 dark:text-zinc-950">3</span>
                    </span>
                </div>

                <div class="grid gap-3 p-5 sm:grid-cols-3">
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/50 dark:bg-emerald-950/20">
                        <p class="text-sm font-semibold text-emerald-900 dark:text-emerald-100">Месяц закрыт</p>
                        <p class="mt-1 text-xs text-emerald-800 dark:text-emerald-200">
                            Расчётный месяц: 05.2026. Активных абонентов: 12 480. Создано начислений: 12 480. Пропущено ранее созданных: 0. Ошибок данных: 0.
                        </p>
                    </div>

                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/50 dark:bg-amber-950/20">
                        <p class="text-sm font-semibold text-amber-900 dark:text-amber-100">Месяц закрыт с ошибками</p>
                        <p class="mt-1 text-xs text-amber-800 dark:text-amber-200">
                            Расчётный месяц: 05.2026. Активных абонентов: 12 480. Создано начислений: 0. Ошибок данных: 37. Откройте отчёт ошибок в разделе «Расчётные месяцы».
                        </p>
                    </div>

                    <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-900/50 dark:bg-rose-950/20">
                        <p class="text-sm font-semibold text-rose-900 dark:text-rose-100">Не удалось закрыть месяц</p>
                        <p class="mt-1 text-xs text-rose-800 dark:text-rose-200">
                            Расчётный месяц: 05.2026. Закрытие прервано технической ошибкой, начисления не созданы. Запустите закрытие месяца заново.
                        </p>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white shadow-sm ring-1 ring-zinc-950/5 dark:border-white/10 dark:bg-zinc-900 dark:ring-white/10">
                <div class="flex flex-col gap-3 border-b border-zinc-200 p-5 dark:border-white/10 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm text-rose-700 dark:text-rose-300">Расчётные месяцы</p>
                        <h2 class="text-base font-semibold">Отчёт ошибок закрытия</h2>
                        <p class="mt-2 max-w-2xl text-sm text-zinc-500 dark:text-zinc-400">
                            Отдельная страница показывает сводку, разбивку по стабильным кодам ошибок и постраничную таблицу по абонентам с выгрузкой в Excel.
                        </p>
                    </div>
                    <span class="w-fit rounded-md bg-rose-100 px-2 py-1 text-xs font-medium text-rose-700 ring-1 ring-rose-600/10 dark:bg-rose-400/10 dark:text-rose-300 dark:ring-rose-400/20">Ошибка закрытия</span>
                </div>

                <div class="p-5">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-white/5">
                            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Расчётный месяц</div>
                            <div class="mt-1 text-lg font-semibold text-zinc-950 dark:text-white">03.2026</div>
                        </div>
                        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-900/50 dark:bg-rose-950/20">
                            <div class="text-xs font-medium text-rose-700 dark:text-rose-300">Ошибок данных</div>
                            <div class="mt-1 text-lg font-semibold text-rose-700 dark:text-rose-300">11 166</div>
                        </div>
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-white/5">
                            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Статус</div>
                            <div class="mt-2">
                                <span class="rounded-md bg-rose-100 px-2 py-1 text-xs font-medium text-rose-700 ring-1 ring-rose-600/10 dark:bg-rose-400/10 dark:text-rose-300 dark:ring-rose-400/20">Ошибка закрытия</span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm ring-1 ring-zinc-950/5 dark:border-white/10 dark:bg-zinc-900 dark:ring-white/10">
                        <h3 class="text-sm font-semibold text-zinc-950 dark:text-white">Причины ошибок</h3>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Сколько абонентов остановились на каждой причине.</p>

                        <ul class="mt-3 divide-y divide-zinc-200 dark:divide-white/10">
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                                <div class="min-w-0">
                                    <div class="text-sm font-medium text-zinc-950 dark:text-white">Не найдены активные счётчики по услуге организации.</div>
                                    <div class="mt-1">
                                        <span class="rounded-md bg-amber-100 px-2 py-1 text-xs font-medium text-amber-700 ring-1 ring-amber-600/10 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20">missing_active_meters</span>
                                    </div>
                                </div>
                                <div class="text-lg font-semibold text-zinc-950 dark:text-white">11 164</div>
                            </li>
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                                <div class="min-w-0">
                                    <div class="text-sm font-medium text-zinc-950 dark:text-white">Не указана фиксированная сумма.</div>
                                    <div class="mt-1">
                                        <span class="rounded-md bg-amber-100 px-2 py-1 text-xs font-medium text-amber-700 ring-1 ring-amber-600/10 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20">missing_fixed_amount</span>
                                    </div>
                                </div>
                                <div class="text-lg font-semibold text-zinc-950 dark:text-white">2</div>
                            </li>
                        </ul>
                    </div>

                    <div class="mt-5 overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm ring-1 ring-zinc-950/5 dark:border-white/10 dark:bg-zinc-900 dark:ring-white/10">
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-200 divide-y divide-zinc-200 text-left text-sm dark:divide-white/5">
                                <thead>
                                    <tr class="bg-zinc-50 dark:bg-white/5">
                                        <th class="px-4 py-3 font-semibold text-zinc-950 dark:text-white">Лицевой счёт</th>
                                        <th class="px-4 py-3 font-semibold text-zinc-950 dark:text-white">ФИО</th>
                                        <th class="px-4 py-3 font-semibold text-zinc-950 dark:text-white">Тип начисления</th>
                                        <th class="px-4 py-3 font-semibold text-zinc-950 dark:text-white">Код ошибки</th>
                                        <th class="px-4 py-3 font-semibold text-zinc-950 dark:text-white">Причина</th>
                                        <th class="px-4 py-3 font-semibold text-zinc-950 dark:text-white">Контекст</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 dark:divide-white/5">
                                    <tr class="transition hover:bg-zinc-50 dark:hover:bg-white/5">
                                        <td class="px-4 py-4 align-top text-zinc-950 dark:text-white">80502</td>
                                        <td class="px-4 py-4 align-top text-zinc-950 dark:text-white">Без суммы</td>
                                        <td class="px-4 py-4 align-top">
                                            <span class="rounded-md bg-zinc-100 px-2 py-1 text-xs font-medium text-zinc-700 ring-1 ring-zinc-600/10 dark:bg-white/10 dark:text-zinc-200 dark:ring-white/20">Фиксированная сумма</span>
                                        </td>
                                        <td class="px-4 py-4 align-top">
                                            <span class="rounded-md bg-amber-100 px-2 py-1 text-xs font-medium text-amber-700 ring-1 ring-amber-600/10 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20">missing_fixed_amount</span>
                                        </td>
                                        <td class="min-w-80 whitespace-normal px-4 py-4 align-top text-zinc-700 dark:text-zinc-200">Не указана фиксированная сумма.</td>
                                        <td class="px-4 py-4 align-top text-zinc-500 dark:text-zinc-400">-</td>
                                    </tr>
                                    <tr class="transition hover:bg-zinc-50 dark:hover:bg-white/5">
                                        <td class="px-4 py-4 align-top text-zinc-950 dark:text-white">90002</td>
                                        <td class="px-4 py-4 align-top text-zinc-950 dark:text-white">Иванов Иван</td>
                                        <td class="px-4 py-4 align-top">
                                            <span class="rounded-md bg-zinc-100 px-2 py-1 text-xs font-medium text-zinc-700 ring-1 ring-zinc-600/10 dark:bg-white/10 dark:text-zinc-200 dark:ring-white/20">По счётчику</span>
                                        </td>
                                        <td class="px-4 py-4 align-top">
                                            <span class="rounded-md bg-amber-100 px-2 py-1 text-xs font-medium text-amber-700 ring-1 ring-amber-600/10 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20">missing_meter_reading</span>
                                        </td>
                                        <td class="min-w-80 whitespace-normal px-4 py-4 align-top text-zinc-700 dark:text-zinc-200">Нет показания счётчика MTR-90002-2 за период.</td>
                                        <td class="min-w-60 whitespace-normal px-4 py-4 align-top text-zinc-700 dark:text-zinc-200">meter_id: 42; meter_number: MTR-90002-2</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 px-4 py-3 text-sm text-zinc-500 dark:border-white/10 dark:text-zinc-400">
                            <span>Показано с 1 по 50 из 11 166</span>
                            <div class="flex items-center gap-1">
                                <span class="rounded-md bg-amber-100 px-2 py-1 text-xs font-medium text-amber-700 ring-1 ring-amber-600/10 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20">1</span>
                                <span class="rounded-md px-2 py-1 text-xs font-medium ring-1 ring-zinc-950/10 dark:ring-white/20">2</span>
                                <span class="rounded-md px-2 py-1 text-xs font-medium ring-1 ring-zinc-950/10 dark:ring-white/20">3</span>
                                <span class="px-1 text-xs">…</span>
                                <span class="rounded-md px-2 py-1 text-xs font-medium ring-1 ring-zinc-950/10 dark:ring-white/20">224</span>
                            </div>
                        </div>
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
