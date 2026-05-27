<x-filament-panels::page>
    <form wire:submit="close" class="space-y-6">
        <x-filament::section>
            {{ $this->form }}

            <div class="mt-6">
                <x-filament::button type="submit" icon="heroicon-o-calculator">
                    Закрыть месяц
                </x-filament::button>
            </div>
        </x-filament::section>
    </form>

    @if ($result)
        <x-filament::section>
            <x-slot name="heading">
                Результат
            </x-slot>

            <dl class="grid gap-4 sm:grid-cols-4">
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Активных</dt>
                    <dd class="text-xl font-semibold text-gray-950 dark:text-white">{{ $result['active'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Создано</dt>
                    <dd class="text-xl font-semibold text-gray-950 dark:text-white">{{ $result['created'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Пропущено</dt>
                    <dd class="text-xl font-semibold text-gray-950 dark:text-white">{{ $result['skipped'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Ошибок</dt>
                    <dd class="text-xl font-semibold text-gray-950 dark:text-white">{{ $result['failed'] }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                Ошибки
            </x-slot>

            @if ($result['errors'] === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Ошибок данных нет.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="py-2 pe-4 font-medium text-gray-950 dark:text-white">Лицевой счёт</th>
                                <th class="py-2 pe-4 font-medium text-gray-950 dark:text-white">Абонент</th>
                                <th class="py-2 font-medium text-gray-950 dark:text-white">Ошибка</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($result['errors'] as $error)
                                <tr class="border-b border-gray-100 last:border-b-0 dark:border-white/5">
                                    <td class="py-2 pe-4 text-gray-700 dark:text-gray-200">{{ $error['account_number'] }}</td>
                                    <td class="py-2 pe-4 text-gray-700 dark:text-gray-200">{{ $error['client_name'] }}</td>
                                    <td class="py-2 text-gray-700 dark:text-gray-200">{{ $error['message'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
