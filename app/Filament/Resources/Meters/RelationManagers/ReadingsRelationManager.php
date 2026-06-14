<?php

namespace App\Filament\Resources\Meters\RelationManagers;

use App\Filament\Support\CurrentBillingPeriod;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ReadingsRelationManager extends RelationManager
{
    protected static string $relationship = 'readings';

    protected static ?string $title = 'Показания';

    protected static ?string $modelLabel = 'показание';

    protected static ?string $pluralModelLabel = 'показания';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Показание')
                    ->columns(2)
                    ->schema([
                        TextInput::make('previous_reading')
                            ->label('Предыдущее показание')
                            ->numeric()
                            ->step('0.0001')
                            ->minValue(0)
                            ->default(fn (): float => $this->previousReadingForPeriod($this->currentBillingPeriodId()))
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
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('period')
            ->modifyQueryUsing(function (Builder $query): Builder {
                $query
                    ->with('billingPeriod')
                    ->orderByBillingPeriodDesc();

                if ($this->canAccessOwnerRecord()) {
                    return $query;
                }

                return $query->whereRaw('1 = 0');
            })
            ->columns([
                TextColumn::make('period')
                    ->label('Период')
                    ->placeholder('-'),
                TextColumn::make('previous_reading')
                    ->label('Предыдущее')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('current_reading')
                    ->label('Текущее')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('consumption')
                    ->label('Расход')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('read_at')
                    ->label('Дата ввода')
                    ->date('d.m.Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->canCreateReadingForOwner())
                    ->disabled(fn (): bool => CurrentBillingPeriod::missing($this->ownerRecord->organization))
                    ->tooltip(fn (): ?string => CurrentBillingPeriod::missingTooltip($this->ownerRecord->organization))
                    ->mutateDataUsing(function (array $data): array {
                        abort_unless($this->canCreateReadingForOwner(), 403);

                        $billingPeriod = $this->currentBillingPeriod();
                        $this->ensureReadingDoesNotAlreadyExist($this->ownerRecord->getKey(), $billingPeriod->getKey());

                        $data['billing_period_id'] = $billingPeriod->getKey();
                        $data['previous_reading'] = $this->previousReadingForPeriod($billingPeriod->getKey());

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (MeterReading $record): bool => $this->canEditReading($record)),
                DeleteAction::make()
                    ->visible(fn (MeterReading $record): bool => OrganizationMemberAccess::canDeleteMeterReading($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => OrganizationMemberAccess::canManageTenant()),
                ]),
            ]);
    }

    protected function previousReadingForPeriod(mixed $billingPeriodId): float
    {
        return MeterReading::previousReadingForBillingPeriod(
            $this->ownerRecord->getKey(),
            $billingPeriodId,
        ) ?? 0;
    }

    private function ensureReadingDoesNotAlreadyExist(mixed $meterId, int|string $billingPeriodId): void
    {
        if (! MeterReading::existsForMeterBillingPeriod($meterId, $billingPeriodId)) {
            return;
        }

        throw ValidationException::withMessages([
            $this->mountedActionFieldErrorKey('current_reading') => MeterReading::DUPLICATE_BILLING_PERIOD_MESSAGE,
        ]);
    }

    private function mountedActionFieldErrorKey(string $field): string
    {
        $schemaName = $this->getMountedActionSchemaName();
        $statePath = $schemaName ? $this->{$schemaName}->getStatePath() : null;

        return filled($statePath) ? "{$statePath}.{$field}" : $field;
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

        return $this->ownerRecord instanceof Meter
            && $tenant instanceof Organization
            && $user instanceof User
            && $user->canAccessMeterInOrganization($this->ownerRecord, $tenant);
    }

    private function canCreateReadingForOwner(): bool
    {
        return $this->ownerRecord instanceof Meter
            && OrganizationMemberAccess::canCreateMeterReadingForMeter($this->ownerRecord);
    }

    private function canEditReading(MeterReading $meterReading): bool
    {
        return ($meterReading->billingPeriod?->isEditable() ?? false)
            && $this->canCreateReadingForOwner()
            && OrganizationMemberAccess::canUpdateMeterReading($meterReading);
    }
}
