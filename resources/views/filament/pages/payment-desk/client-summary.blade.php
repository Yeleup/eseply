@php
    use Filament\Support\Icons\Heroicon;

    // Корректировки показываются, только когда они есть, — иначе плитки
    // сальдо, начисления и оплат уже складываются в итог.
    $hasAdjustment = round($balance['adjustment'], 2) !== 0.0;
@endphp

<div class="flex flex-col gap-4">
    <section class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
        <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-4 py-2 dark:border-white/10 dark:bg-white/5">
            <h3 class="text-xs font-semibold text-gray-600 dark:text-gray-300">Карточка абонента</h3>

            @if ($client->status !== 'active')
                <x-filament::badge color="warning">Абонент не активен</x-filament::badge>
            @endif
        </div>

        <dl class="grid grid-cols-1 gap-px bg-gray-100 sm:grid-cols-2 dark:bg-white/5">
            <div class="bg-white px-4 py-2 dark:bg-gray-900">
                <dt class="text-xs text-gray-500 dark:text-gray-400">Телефон</dt>
                <dd class="mt-0.5 text-sm font-medium text-gray-950 dark:text-white">{{ filled($client->phone) ? $client->phone : '—' }}</dd>
            </div>
            <div class="bg-white px-4 py-2 dark:bg-gray-900">
                <dt class="text-xs text-gray-500 dark:text-gray-400">Проживающих</dt>
                <dd class="mt-0.5 text-sm font-medium text-gray-950 dark:text-white">{{ $client->residents_count ?? '—' }}</dd>
            </div>
            <div class="bg-white px-4 py-2 dark:bg-gray-900">
                <dt class="text-xs text-gray-500 dark:text-gray-400">Услуги</dt>
                <dd class="mt-0.5 text-sm font-medium text-gray-950 dark:text-white">{{ $serviceName ?? '—' }}</dd>
            </div>
            <div class="bg-white px-4 py-2 dark:bg-gray-900">
                <dt class="text-xs text-gray-500 dark:text-gray-400">Последняя оплата</dt>
                <dd class="mt-0.5 text-sm font-medium text-gray-950 dark:text-white">{{ $lastPayment ?? 'Оплат ещё не было' }}</dd>
            </div>
        </dl>
    </section>

    <div class="grid grid-cols-2 gap-3">
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5">
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Сальдо на начало</div>
            <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $this->money($balance['opening']) }}</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5">
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Начислено</div>
            <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $this->money($balance['accrued']) }}</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5">
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Оплачено</div>
            <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $this->money($balance['paid']) }}</div>
        </div>

        @if ($hasAdjustment)
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Корректировки</div>
                <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $this->money($balance['adjustment']) }}</div>
            </div>
        @endif

        @if ($balance['credit'] > 0)
            <div @class([
                'rounded-xl border border-sky-200 bg-sky-50 p-3 dark:border-sky-900/50 dark:bg-sky-950/20',
                'col-span-2' => $hasAdjustment,
            ])>
                <div class="text-xs font-medium text-sky-700 dark:text-sky-300">Переплата</div>
                <div class="mt-1 text-lg font-semibold text-sky-700 dark:text-sky-300">{{ $this->money($balance['credit']) }}</div>
            </div>
        @elseif ($balance['debt'] > 0)
            <div @class([
                'rounded-xl border border-rose-200 bg-rose-50 p-3 dark:border-rose-900/50 dark:bg-rose-950/20',
                'col-span-2' => $hasAdjustment,
            ])>
                <div class="text-xs font-medium text-rose-700 dark:text-rose-300">К оплате</div>
                <div class="mt-1 text-lg font-semibold text-rose-700 dark:text-rose-300">{{ $this->money($balance['debt']) }}</div>
            </div>
        @else
            <div @class([
                'rounded-xl border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-900/50 dark:bg-emerald-950/20',
                'col-span-2' => $hasAdjustment,
            ])>
                <div class="text-xs font-medium text-emerald-700 dark:text-emerald-300">К оплате</div>
                <div class="mt-2">
                    <x-filament::badge color="success" :icon="Heroicon::OutlinedCheckCircle">Долга нет</x-filament::badge>
                </div>
            </div>
        @endif
    </div>
</div>
