<?php

namespace App\Filament\Resources\Receipts\Pages;

use App\Filament\Resources\Receipts\ReceiptResource;
use App\Models\Receipt;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewReceipt extends ViewRecord
{
    protected static string $resource = ReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('printPdf')
                ->label('Печатать PDF')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn (): string => route('filament.admin.receipts.print', [
                    'tenant' => Filament::getTenant(),
                    'receipt' => $this->record,
                ]), shouldOpenInNewTab: true),
        ];
    }

    protected function authorizeAccess(): void
    {
        abort_unless(
            $this->record instanceof Receipt
                && static::getResource()::canView($this->record),
            404,
        );
    }
}
