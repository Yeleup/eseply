<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\Client;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected function authorizeAccess(): void
    {
        $record = $this->getRecord();

        abort_unless(
            $record instanceof Client
                && OrganizationMemberAccess::canViewClient($record),
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
            Action::make('card')
                ->label('Карточка')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->url(fn (): string => route('filament.admin.clients.card', [
                    'tenant' => Filament::getTenant(),
                    'client' => $this->record,
                ]), shouldOpenInNewTab: true),
            DeleteAction::make()
                ->visible(fn (): bool => $this->record instanceof Client && OrganizationMemberAccess::canDeleteClient($this->record)),
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
        abort_unless($record instanceof Client && OrganizationMemberAccess::canManageClient($record), 403);

        return parent::handleRecordUpdate($record, $data);
    }

    private function canManageRecord(): bool
    {
        return $this->record instanceof Client
            && OrganizationMemberAccess::canManageClient($this->record);
    }
}
