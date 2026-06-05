<?php

namespace App\Filament\Resources\Meters\Pages;

use App\Filament\Resources\Meters\MeterResource;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\Meter;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class EditMeter extends EditRecord
{
    protected static string $resource = MeterResource::class;

    protected function authorizeAccess(): void
    {
        $record = $this->getRecord();

        abort_unless(
            $record instanceof Meter
                && OrganizationMemberAccess::canViewMeter($record),
            404,
        );
    }

    public function form(Schema $schema): Schema
    {
        return parent::form($schema)
            ->disabled(fn (): bool => ! $this->canManageRecord());
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => $this->record instanceof Meter && OrganizationMemberAccess::canDeleteMeter($this->record)),
        ];
    }

    protected function getFormActions(): array
    {
        if (! $this->canManageRecord()) {
            return [
                $this->getCancelFormAction(),
            ];
        }

        return parent::getFormActions();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Meter && OrganizationMemberAccess::canManageMeter($record), 403);

        return parent::handleRecordUpdate($record, $data);
    }

    private function canManageRecord(): bool
    {
        return $this->record instanceof Meter
            && OrganizationMemberAccess::canManageMeter($this->record);
    }
}
