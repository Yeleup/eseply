<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($this->getReports() as $report)
            <a
                href="{{ \App\Filament\Pages\Reports\ViewReport::getUrl(['report' => $report->slug()]) }}"
                class="group rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-primary-500 hover:shadow-md dark:border-white/10 dark:bg-gray-900"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="space-y-2">
                        <h2 class="text-base font-semibold text-gray-950 group-hover:text-primary-600 dark:text-white dark:group-hover:text-primary-400">
                            {{ $report->title() }}
                        </h2>

                        @if ($report->description())
                            <p class="text-sm leading-6 text-gray-500 dark:text-gray-400">
                                {{ $report->description() }}
                            </p>
                        @endif
                    </div>

                    <x-filament::icon
                        icon="heroicon-o-arrow-right"
                        class="h-5 w-5 shrink-0 text-gray-400 transition group-hover:translate-x-0.5 group-hover:text-primary-500"
                    />
                </div>
            </a>
        @endforeach
    </div>
</x-filament-panels::page>
