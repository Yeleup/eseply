<?php

namespace App\Filament\Resources\MeterReadings\Schemas;

use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use Closure;
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
                        self::currentReadingInput(fn (Get $get): int => MeterReading::previousReadingForBillingPeriod(
                            $get('meter_id'),
                            self::currentBillingPeriodId(),
                        ) ?? 0),
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

    /**
     * The current reading input, shared by every form that writes a reading.
     *
     * The previous reading comes from the resolver and never from the
     * `previous_reading` field: that field is part of the client-controlled
     * Livewire state, so a tampered value would lift the restriction.
     *
     * @param  Closure(): int  $previousReading  Evaluated by the component, so it may ask for `Get $get`.
     * @param  Closure(): (int|null)|null  $storedReading  The value already saved for the row, when the record of the component is not the reading itself.
     */
    public static function currentReadingInput(Closure $previousReading, ?Closure $storedReading = null): TextInput
    {
        return TextInput::make('current_reading')
            ->label('Текущее показание')
            ->integer()
            ->minValue(0)
            ->maxValue(MeterReading::MAXIMUM_CURRENT_READING)
            ->rules([
                'max:'.MeterReading::MAXIMUM_CURRENT_READING,
                fn (TextInput $component): Closure => function (string $attribute, mixed $value, Closure $fail) use ($component, $previousReading, $storedReading): void {
                    if (blank($value) || OrganizationMemberAccess::canEnterMeterReadingBelowPrevious()) {
                        return;
                    }

                    $previous = (int) $component->evaluate($previousReading);

                    if ((int) $value >= $previous) {
                        return;
                    }

                    // The stored value may itself be below the previous reading,
                    // because an operator saved it after a rollover. Re-saving
                    // the row unchanged — to attach a photo, a note or a date —
                    // has to stay possible for the controller.
                    if ((int) $value === self::storedCurrentReading($component, $storedReading)) {
                        return;
                    }

                    $fail(MeterReading::belowPreviousReadingMessage($previous));
                },
            ])
            ->validationMessages([
                'max' => MeterReading::maximumCurrentReadingMessage(),
            ])
            ->required();
    }

    private static function storedCurrentReading(TextInput $component, ?Closure $storedReading): ?int
    {
        $stored = $storedReading instanceof Closure
            ? $component->evaluate($storedReading)
            // The record of the component is the reading itself on the resource
            // edit page and in the readings relation manager; elsewhere it is
            // the meter, and the caller passes an explicit resolver.
            : ($component->getRecord() instanceof MeterReading ? $component->getRecord()->current_reading : null);

        return $stored === null ? null : (int) $stored;
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
            // FilePond checks the size of the file as it was picked, before the
            // resize below shrinks it, so the ceiling has to clear what a phone
            // camera actually produces: a 48MP shot passes 10 MB easily and
            // would be rejected before it could ever be downscaled. What
            // reaches the server is the resized image, a few hundred kilobytes.
            ->maxSize(25600)
            ->automaticallyResizeImagesMode('contain')
            ->automaticallyResizeImagesToWidth('1920')
            ->automaticallyResizeImagesToHeight('1920')
            ->openable()
            // Splits the single drop area into «Сделать фото» and «Из галереи»
            // on touch devices. `x-init` sits on the same element as Filament's
            // own `x-data`, so Alpine runs it whenever the field appears —
            // including inside a modal opened long after the page loaded, which
            // is exactly how the controller reaches this field.
            ->extraAlpineAttributes([
                'x-init' => 'window.initMeterPhotoCapture?.($el, $data)',
            ])
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
