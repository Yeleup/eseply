<?php

namespace App\Filament\Resources\BalanceAdjustments\Pages;

use App\Filament\Resources\BalanceAdjustments\BalanceAdjustmentResource;
use App\Models\BalanceAdjustment;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;

class EditBalanceAdjustment extends EditRecord
{
    protected static string $resource = BalanceAdjustmentResource::class;

    public function form(Schema $schema): Schema
    {
        return parent::form($schema)
            ->disabled(fn (): bool => $this->record instanceof BalanceAdjustment && ! $this->record->billingPeriod?->isEditable());
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => $this->record instanceof BalanceAdjustment && $this->record->billingPeriod?->isEditable()),
        ];
    }
}
