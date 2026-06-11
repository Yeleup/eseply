<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;

class EditPayment extends EditRecord
{
    protected static string $resource = PaymentResource::class;

    public function form(Schema $schema): Schema
    {
        return parent::form($schema)
            ->disabled(fn (): bool => $this->record instanceof Payment && ! $this->record->billingPeriod?->isEditable());
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => $this->record instanceof Payment && $this->record->billingPeriod?->isEditable()),
        ];
    }
}
