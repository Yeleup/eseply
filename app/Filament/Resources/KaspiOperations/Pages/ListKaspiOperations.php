<?php

namespace App\Filament\Resources\KaspiOperations\Pages;

use App\Filament\Resources\KaspiOperations\KaspiOperationResource;
use App\Filament\Support\OrganizationMemberAccess;
use App\Filament\Widgets\KaspiOperationsStatsWidget;
use App\Kaspi\KaspiReconciliationExport;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListKaspiOperations extends ListRecords
{
    protected static string $resource = KaspiOperationResource::class;

    public function mount(): void
    {
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);

        parent::mount();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Одна строка на операцию Kaspi (номер Kaspi): повторы считаются в ней же. «В обработке» проведётся само после закрытия месяца, повтор Kaspi для этого не нужен.';
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            KaspiOperationsStatsWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportReconciliation')
                ->label('Выгрузить для сверки')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->modalHeading('Выгрузить для сверки')
                ->modalDescription('Оплаты Kaspi за день по дате Kaspi, как в их реестре: проведённые и «в обработке», одна строка на номер Kaspi. Столбцы XLSX: Номер Kaspi, Дата Kaspi, Лицевой счёт, Абонент, Сумма, Наш номер оплаты, Состояние.')
                ->modalSubmitActionLabel('Скачать XLSX')
                ->schema([
                    DatePicker::make('date')
                        ->label('Дата Kaspi')
                        // Kaspi sends the register for the previous day.
                        ->default(fn (): string => today()->subDay()->toDateString())
                        ->maxDate(fn (): string => today()->toDateString())
                        ->required()
                        ->native(false),
                ])
                ->action(fn (array $data): StreamedResponse => $this->exportReconciliation($data)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function exportReconciliation(array $data): StreamedResponse
    {
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);

        $organization = OrganizationMemberAccess::tenant();
        abort_unless($organization !== null, 404);

        return app(KaspiReconciliationExport::class)->download(
            $organization,
            CarbonImmutable::parse((string) $data['date'])->startOfDay(),
        );
    }
}
