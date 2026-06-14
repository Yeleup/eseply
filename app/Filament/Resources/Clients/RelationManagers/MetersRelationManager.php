<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Meters\MeterResource;
use App\Filament\Support\CurrentBillingPeriod;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetersRelationManager extends RelationManager
{
    protected static string $relationship = 'meters';

    protected static ?string $title = 'Счётчики';

    protected static ?string $modelLabel = 'счётчик';

    protected static ?string $pluralModelLabel = 'счётчики';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Счётчик')
                    ->columns(2)
                    ->schema([
                        TextInput::make('number')
                            ->label('Номер счётчика')
                            ->required()
                            ->maxLength(255)
                            ->scopedUnique(),
                        TextInput::make('initial_reading')
                            ->label('Начальное показание')
                            ->numeric()
                            ->step('0.0001')
                            ->minValue(0)
                            ->default(0)
                            ->disabledOn('edit')
                            ->required(),
                        DatePicker::make('installed_on')
                            ->label('Дата установки')
                            ->default(fn (): string => today()->toDateString())
                            ->native(false),
                        Textarea::make('note')
                            ->label('Примечание')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->modifyQueryUsing(function (Builder $query): Builder {
                $currentBillingPeriodId = $this->currentBillingPeriodId();

                $query
                    ->with('utilityService')
                    ->orderBy('number');

                if ($currentBillingPeriodId !== null) {
                    $query->with([
                        'readings' => fn (HasMany $query): HasMany => $query
                            ->where('billing_period_id', $currentBillingPeriodId),
                    ]);
                }

                if ($this->canAccessOwnerRecord()) {
                    return $query;
                }

                return $query->whereRaw('1 = 0');
            })
            ->columns([
                TextColumn::make('number')
                    ->label('Номер')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('utilityService.name')
                    ->label('Услуга')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Не указана'),
                TextColumn::make('initial_reading')
                    ->label('Начальное показание')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'removed' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'Активный',
                        'removed' => 'В архиве',
                        default => $state,
                    })
                    ->sortable(),
                TextColumn::make('installed_on')
                    ->label('Установлен')
                    ->date('d.m.Y')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('removed_on')
                    ->label('Снят')
                    ->date('d.m.Y')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'active' => 'Активный',
                        'removed' => 'В архиве',
                    ]),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->canCreateMeterForOwner())
                    ->mutateDataUsing(function (array $data): array {
                        abort_unless($this->canCreateMeterForOwner(), 403);

                        $data['organization_id'] = $this->ownerRecord->organization_id;
                        $data['utility_service_id'] = $this->ownerRecord->utility_service_id;

                        return $data;
                    }),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Открыть')
                    ->url(fn (Meter $record): string => MeterResource::getUrl('edit', ['record' => $record])),
                Action::make('addReading')
                    ->label(fn (Meter $record): string => $this->readingActionLabel($record))
                    ->icon(fn (Meter $record): Heroicon => $this->currentReadingForMeter($record) instanceof MeterReading
                        ? Heroicon::PencilSquare
                        : Heroicon::PlusCircle)
                    ->color(fn (Meter $record): string => $this->currentReadingForMeter($record) instanceof MeterReading ? 'warning' : 'success')
                    ->modalHeading(fn (Meter $record): string => "{$this->readingActionLabel($record)}: {$record->number}")
                    ->modalSubmitActionLabel(fn (Meter $record): string => $this->currentReadingForMeter($record) instanceof MeterReading ? 'Сохранить' : 'Добавить')
                    ->successNotificationTitle('Показание сохранено')
                    ->schema(fn (Meter $record): array => $this->readingFormComponents($record))
                    ->fillForm(fn (Meter $record): array => $this->readingActionFormData($record))
                    ->visible(fn (Meter $record): bool => $this->canAddReadingForMeter($record))
                    ->disabled(fn (Meter $record): bool => CurrentBillingPeriod::missing($record->organization))
                    ->tooltip(fn (Meter $record): ?string => CurrentBillingPeriod::missingTooltip($record->organization))
                    ->action(function (Meter $record, array $data): void {
                        abort_unless($this->canAddReadingForMeter($record), 403);

                        $billingPeriod = $this->currentBillingPeriod();
                        $meterReading = $this->currentReadingForMeter($record, $billingPeriod->getKey());

                        $readingData = [
                            'previous_reading' => $meterReading?->previous_reading
                                ?? $this->previousReadingForMeterAndPeriod($record, $billingPeriod->getKey()),
                            'current_reading' => $data['current_reading'],
                            'read_at' => $data['read_at'] ?? null,
                            'note' => $data['note'] ?? null,
                        ];

                        if ($meterReading instanceof MeterReading) {
                            abort_unless($this->canEditReading($meterReading), 403);

                            $meterReading->update($readingData);

                            return;
                        }

                        $record->readings()->create([
                            'billing_period_id' => $billingPeriod->getKey(),
                            ...$readingData,
                        ]);
                    }),
                Action::make('archive')
                    ->label('Отправить в архив')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Отправить счётчик в архив?')
                    ->modalDescription('Дата снятия будет проставлена сегодняшней датой.')
                    ->modalSubmitActionLabel('Отправить в архив')
                    ->visible(fn (Meter $record): bool => ! $record->isArchived() && $this->canManageMeter($record))
                    ->action(function (Meter $record): void {
                        abort_unless($this->canManageMeter($record), 403);

                        $record->archive();
                    }),
                Action::make('restoreFromArchive')
                    ->label('Вывести из архива')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Вывести счётчик из архива?')
                    ->modalDescription('Дата снятия будет очищена, счётчик снова станет активным.')
                    ->modalSubmitActionLabel('Вывести из архива')
                    ->visible(fn (Meter $record): bool => $record->isArchived() && $this->canManageMeter($record))
                    ->action(function (Meter $record): void {
                        abort_unless($this->canManageMeter($record), 403);

                        $record->restoreFromArchive();
                    }),
                EditAction::make()
                    ->visible(fn (Meter $record): bool => $this->canManageMeter($record)),
                DeleteAction::make()
                    ->visible(fn (Meter $record): bool => $this->canManageMeter($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => OrganizationMemberAccess::canManageTenant()),
                ]),
            ]);
    }

    /**
     * @return array<int, Section>
     */
    protected function readingFormComponents(Meter $meter): array
    {
        return [
            Section::make('Показание')
                ->columns(2)
                ->schema([
                    TextInput::make('previous_reading')
                        ->label('Предыдущее показание')
                        ->numeric()
                        ->step('0.0001')
                        ->minValue(0)
                        ->default(fn (): float => $this->previousReadingForMeterAndPeriod($meter, $this->currentBillingPeriodId()))
                        ->readOnly()
                        ->required(),
                    TextInput::make('current_reading')
                        ->label('Текущее показание')
                        ->numeric()
                        ->step('0.0001')
                        ->minValue(0)
                        ->required(),
                    DatePicker::make('read_at')
                        ->label('Дата ввода')
                        ->native(false),
                    Textarea::make('note')
                        ->label('Примечание')
                        ->columnSpanFull(),
                ]),
        ];
    }

    protected function previousReadingForMeterAndPeriod(Meter $meter, mixed $billingPeriodId): float
    {
        return MeterReading::previousReadingForBillingPeriod($meter->getKey(), $billingPeriodId) ?? 0;
    }

    private function readingActionLabel(Meter $meter): string
    {
        return $this->currentReadingForMeter($meter) instanceof MeterReading
            ? 'Изменить показание'
            : 'Добавить показание';
    }

    /**
     * @return array{previous_reading: float, current_reading?: float|null, read_at?: string|null, note?: string|null}
     */
    private function readingActionFormData(Meter $meter): array
    {
        $billingPeriodId = $this->currentBillingPeriodId();
        $meterReading = $this->currentReadingForMeter($meter, $billingPeriodId);

        if ($meterReading instanceof MeterReading) {
            return [
                'previous_reading' => (float) $meterReading->previous_reading,
                'current_reading' => (float) $meterReading->current_reading,
                'read_at' => $meterReading->read_at?->toDateString(),
                'note' => $meterReading->note,
            ];
        }

        return [
            'previous_reading' => $this->previousReadingForMeterAndPeriod($meter, $billingPeriodId),
        ];
    }

    private function currentReadingForMeter(Meter $meter, int|string|null $billingPeriodId = null): ?MeterReading
    {
        $billingPeriodId ??= $this->currentBillingPeriodId();

        if ($billingPeriodId === null || $billingPeriodId === '') {
            return null;
        }

        if ($meter->relationLoaded('readings')) {
            $meterReading = $meter->readings
                ->first(fn (MeterReading $meterReading): bool => (int) $meterReading->billing_period_id === (int) $billingPeriodId);

            return $meterReading instanceof MeterReading ? $meterReading : null;
        }

        return MeterReading::query()
            ->where('meter_id', $meter->getKey())
            ->where('billing_period_id', $billingPeriodId)
            ->first();
    }

    private function currentBillingPeriod(): BillingPeriod
    {
        return BillingPeriod::requireCurrentEditableFor($this->ownerRecord->organization);
    }

    private function currentBillingPeriodId(): ?int
    {
        return BillingPeriod::currentEditableFor($this->ownerRecord->organization)?->getKey();
    }

    private function canAccessOwnerRecord(): bool
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        return $this->ownerRecord instanceof Client
            && $tenant instanceof Organization
            && $user instanceof User
            && $user->canAccessClientInOrganization($this->ownerRecord, $tenant);
    }

    private function canCreateMeterForOwner(): bool
    {
        return $this->canAccessOwnerRecord()
            && OrganizationMemberAccess::canCreateMeters();
    }

    private function canManageMeter(Meter $meter): bool
    {
        return OrganizationMemberAccess::canManageMeter($meter);
    }

    private function canAddReadingForMeter(Meter $meter): bool
    {
        return OrganizationMemberAccess::canCreateMeterReadingForMeter($meter);
    }

    private function canEditReading(MeterReading $meterReading): bool
    {
        return ($meterReading->billingPeriod?->isEditable() ?? false)
            && OrganizationMemberAccess::canUpdateMeterReading($meterReading);
    }
}
