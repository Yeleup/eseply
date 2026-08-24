<?php

namespace App\Filament\Resources\MeterReadings\Schemas;

use App\Models\BillingPeriod;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MeterReadingForm
{
    private const int OPTIONS_LIMIT = 50;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Показание')
                    ->columns(2)
                    ->schema([
                        Select::make('meter_id')
                            ->label('Счётчик')
                            ->options(fn (): array => self::meterOptions(null))
                            ->getSearchResultsUsing(fn (string $search): array => self::meterOptions($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => self::meterOptionLabel($value))
                            ->searchable()
                            ->required()
                            ->scopedExists(Meter::class, 'id')
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                $set('previous_reading', MeterReading::previousReadingForBillingPeriod($state, self::currentBillingPeriodId()) ?? 0);
                            })
                            ->native(false),
                        TextInput::make('previous_reading')
                            ->label('Предыдущее показание')
                            ->integer()
                            ->minValue(0)
                            ->default(fn (Get $get): int => MeterReading::previousReadingForBillingPeriod($get('meter_id'), self::currentBillingPeriodId()) ?? 0)
                            ->readOnly()
                            ->required(),
                        TextInput::make('current_reading')
                            ->label('Текущее показание')
                            ->integer()
                            ->minValue(0)
                            ->required(),
                        DatePicker::make('read_at')
                            ->label('Дата ввода')
                            ->native(false),
                        Textarea::make('note')
                            ->label('Примечание')
                            ->columnSpanFull(),
                        self::photoUpload(),
                    ]),
            ]);
    }

    public static function photoUpload(): FileUpload
    {
        return FileUpload::make('photo_path')
            ->label('Фото счётчика')
            ->image()
            ->disk(MeterReading::PHOTO_DISK)
            ->directory(function (): string {
                $tenant = Filament::getTenant();

                return MeterReading::photoDirectoryFor(
                    $tenant instanceof Organization ? $tenant->getKey() : 0,
                );
            })
            ->maxSize(10240)
            ->automaticallyResizeImagesMode('contain')
            ->automaticallyResizeImagesToWidth('1920')
            ->automaticallyResizeImagesToHeight('1920')
            ->openable()
            ->preventFilePathTampering(allowFilePathUsing: function (string $file, Get $get, ?Model $record, Component $component): bool {
                $tenant = Filament::getTenant();

                if (! $tenant instanceof Organization) {
                    return false;
                }

                $directory = MeterReading::photoDirectoryFor($tenant->getKey()).'/';

                if (! str_starts_with($file, $directory)) {
                    return false;
                }

                $meterId = self::meterIdForPhotoValidation($get, $record, $component);

                if ($meterId === null) {
                    return false;
                }

                return MeterReading::query()
                    ->where('organization_id', $tenant->getKey())
                    ->where('meter_id', $meterId)
                    ->where('photo_path', $file)
                    ->exists();
            })
            ->columnSpanFull();
    }

    private static function meterIdForPhotoValidation(Get $get, ?Model $record, Component $component): ?int
    {
        // Authoritative, server-controlled contexts take priority over the
        // "meter_id" form state: in these contexts the field isn't part of
        // the schema, so a crafted Livewire payload could otherwise inject
        // a "meter_id" key into the raw component state and make the
        // validator check a different meter than the one the reading is
        // actually written to.
        if ($record instanceof Meter) {
            return (int) $record->getKey();
        }

        $livewire = $component->getLivewire();

        if ($livewire instanceof RelationManager) {
            $ownerRecord = $livewire->getOwnerRecord();

            if ($ownerRecord instanceof Meter) {
                return (int) $ownerRecord->getKey();
            }
        }

        // On the meter reading resource form "meter_id" is a real,
        // user-editable field, and its state is what will actually be
        // saved. It must be checked before falling back to the record's
        // stored meter_id, otherwise switching the meter while keeping an
        // existing photo path would validate against the meter being left
        // instead of the meter being saved to.
        $meterId = $get('meter_id');

        if (filled($meterId)) {
            return (int) $meterId;
        }

        if ($record instanceof MeterReading) {
            return (int) $record->meter_id;
        }

        return null;
    }

    /**
     * Meters are searched in the database: loading every meter of the
     * organization with its client just to fill a dropdown made the form pay
     * for the whole book to pick one line.
     *
     * @return array<int, string>
     */
    private static function meterOptions(?string $search): array
    {
        $query = self::meterQuery();

        if (! $query instanceof Builder) {
            return [];
        }

        return $query
            ->when(
                filled($search),
                fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('meters.number', 'like', '%'.$search.'%')
                        ->orWhereHas('client', fn (Builder $query): Builder => $query
                            ->where('account_number', 'like', '%'.$search.'%'));
                }),
            )
            ->with('client')
            ->orderBy('meters.number')
            ->limit(self::OPTIONS_LIMIT)
            ->get()
            ->mapWithKeys(fn (Meter $meter): array => [$meter->id => self::meterLabel($meter)])
            ->all();
    }

    private static function meterOptionLabel(mixed $value): ?string
    {
        $query = self::meterQuery();

        if (blank($value) || ! $query instanceof Builder) {
            return null;
        }

        $meter = $query->with('client')->whereKey($value)->first();

        return $meter instanceof Meter ? self::meterLabel($meter) : null;
    }

    private static function meterLabel(Meter $meter): string
    {
        return "{$meter->number} - {$meter->client?->account_number}";
    }

    /**
     * @return Builder<Meter>|null
     */
    private static function meterQuery(): ?Builder
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        if (! $tenant instanceof Organization || ! $user instanceof User) {
            return null;
        }

        return Meter::query()->visibleToOrganizationMember($user, $tenant);
    }

    private static function currentBillingPeriodId(): ?int
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Organization) {
            return null;
        }

        return BillingPeriod::currentEditableFor($tenant)?->getKey();
    }
}
