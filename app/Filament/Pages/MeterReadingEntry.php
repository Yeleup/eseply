<?php

namespace App\Filament\Pages;

use App\Filament\Resources\MeterReadings\Schemas\MeterReadingForm;
use App\Filament\Support\ClientAddressFilter;
use App\Filament\Support\ControllerZoneFilter;
use App\Filament\Support\CurrentBillingPeriod;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;
use UnitEnum;

/**
 * Mass meter reading entry for the field walk of a controller.
 *
 * Rows are meters, not clients, because a client may have several of them, and
 * they are ordered the way the controller physically walks the zone: street,
 * house, apartment. The current reading is typed straight into the row and
 * saved on blur or Enter, so no modal or page reload is needed per client.
 */
class MeterReadingEntry extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'meter-reading-entry';

    protected static ?string $title = 'Ввод показаний';

    protected static ?string $navigationLabel = 'Ввод показаний';

    protected static string|UnitEnum|null $navigationGroup = 'Учёт';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected string $view = 'filament.pages.meter-reading-entry';

    private ?BillingPeriod $billingPeriodCache = null;

    private bool $billingPeriodResolved = false;

    private ?bool $canEnterReadingsCache = null;

    public static function canAccess(): bool
    {
        return OrganizationMemberAccess::canAccessTenant();
    }

    public function mount(): void
    {
        abort_unless(OrganizationMemberAccess::canAccessTenant(), 403);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Выберите улицу и вводите текущие показания прямо в списке. Показание сохраняется, когда вы уходите из поля или нажимаете Enter.';
    }

    public function table(Table $table): Table
    {
        $organization = $this->organization();
        $user = $this->currentUser();

        abort_unless($organization instanceof Organization, 404);
        abort_unless($user instanceof User, 403);

        $billingPeriod = $this->currentBillingPeriod();

        return $table
            ->query($this->metersQuery($organization, $user, $billingPeriod))
            ->stackedOnMobile()
            ->columns($this->columns())
            ->filters($this->filters($organization, $billingPeriod?->getKey()))
            ->recordActions([$this->detailsAction()])
            ->recordClasses(fn (Meter $record): array => $this->hasNegativeConsumption($record)
                ? ['fi-readings-negative']
                : [])
            ->recordUrl(null)
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Нет счётчиков по выбранному адресу')
            ->emptyStateDescription('Снимите фильтры или выберите другую улицу.')
            ->extraAttributes(['class' => 'fi-readings-entry']);
    }

    /**
     * Counters of the header, scoped by the address and controller filters but
     * never by "not taken yet": otherwise saving a reading would shrink the
     * denominator and the progress would never reach 100%.
     *
     * @return array{total: int, taken: int, problem: int, percent: int}
     */
    public function readingProgress(): array
    {
        $organization = $this->organization();
        $user = $this->currentUser();

        if (! $organization instanceof Organization || ! $user instanceof User) {
            return ['total' => 0, 'taken' => 0, 'problem' => 0, 'percent' => 0];
        }

        $query = $this->scopedProgressQuery($organization, $user);
        $total = (clone $query)->count();
        $billingPeriodId = $this->currentBillingPeriod()?->getKey();

        if ($billingPeriodId === null || $total === 0) {
            return ['total' => $total, 'taken' => 0, 'problem' => 0, 'percent' => 0];
        }

        $taken = (clone $query)
            ->whereHas('readings', fn (Builder $query): Builder => $query
                ->where('billing_period_id', $billingPeriodId))
            ->count();

        $problem = (clone $query)
            ->whereHas('readings', fn (Builder $query): Builder => $query
                ->where('billing_period_id', $billingPeriodId)
                ->where('consumption', '<', 0))
            ->count();

        return [
            'total' => $total,
            'taken' => $taken,
            'problem' => $problem,
            'percent' => (int) round($taken / $total * 100),
        ];
    }

    public function hasBillingPeriod(): bool
    {
        return $this->currentBillingPeriod() instanceof BillingPeriod;
    }

    public function billingPeriodLabel(): ?string
    {
        return $this->currentBillingPeriod()?->label;
    }

    /**
     * @return array<int, TextColumn|TextInputColumn>
     */
    private function columns(): array
    {
        return [
            // The address comes first: in the stacked mobile layout the column
            // order is the order of the blocks inside the card, and the address
            // is what the controller matches against the door in front of them.
            TextColumn::make('client_address')
                ->label('Адрес')
                ->state(fn (Meter $record): string => $this->formatAddress($record))
                ->description(fn (Meter $record): ?string => $record->client?->name)
                ->weight(FontWeight::Bold)
                ->extraCellAttributes(['class' => 'fi-readings-address']),

            TextColumn::make('client.account_number')
                ->label('Лицевой счёт')
                ->searchable(query: fn (Builder $query, string $search): Builder => $query
                    ->where('clients.account_number', 'like', '%'.$search.'%')),

            TextColumn::make('number')
                ->label('Счётчик')
                ->searchable(),

            TextColumn::make('previous_reading_for_entry')
                ->label('Предыдущее')
                ->state(fn (Meter $record): int => $this->previousReading($record))
                ->numeric(0),

            TextInputColumn::make('current_reading')
                ->label('Текущее показание')
                // "text" instead of "number": no spinners, no "e"/"-" and no
                // leading-zero surprises on Android.
                ->type('text')
                ->inputMode('numeric')
                // The only channel that reports an error back to the input:
                // everything thrown deeper is swallowed by Livewire.
                ->rules(['integer', 'min:0'])
                ->getStateUsing(fn (Meter $record): ?int => $this->readingFor($record)?->current_reading)
                // Deliberately not tied to the billing period: `isDisabled()`
                // is re-evaluated on every save, so a period closing while the
                // page is open would turn the save into a silent no-op instead
                // of the error the controller has to see.
                ->disabled(fn (): bool => ! $this->canEnterReadings())
                ->updateStateUsing(fn (Meter $record, mixed $state): mixed => $this->saveReading($record, $state))
                ->extraInputAttributes(['class' => 'fi-readings-input']),

            TextColumn::make('consumption_for_entry')
                ->label('Расход')
                ->state(fn (Meter $record): ?int => $this->readingFor($record)?->consumption)
                ->badge()
                ->color(fn (?int $state): string => match (true) {
                    $state === null => 'gray',
                    $state < 0 => 'warning',
                    default => 'success',
                })
                ->placeholder('-'),

            TextColumn::make('read_at_for_entry')
                ->label('Снято')
                ->state(fn (Meter $record) => $this->readingFor($record)?->read_at)
                ->date('d.m.Y')
                ->placeholder('-')
                // "sm", not "md": stackedOnMobile() switches at 640px, so hiding
                // until "md" would punch holes into rows between 640 and 768px.
                ->visibleFrom('sm'),
        ];
    }

    /**
     * @return array<int, BaseFilter>
     */
    private function filters(Organization $organization, ?int $billingPeriodId): array
    {
        return [
            ...$this->scopeFilters($organization),

            // Both filters below depend on the very data being edited, so they
            // are excluded from record resolution: otherwise saving a reading
            // would make its own row unresolvable for the next save.
            Filter::make('not_taken')
                ->label('Только не снятые')
                ->default()
                ->excludeWhenResolvingRecord()
                ->query(fn (Builder $query): Builder => $query->whereDoesntHave(
                    'readings',
                    fn (Builder $readings): Builder => $readings->where('billing_period_id', $billingPeriodId),
                )),

            Filter::make('negative_consumption')
                ->label('Только проблемные')
                ->excludeWhenResolvingRecord()
                ->query(fn (Builder $query): Builder => $query->whereHas(
                    'readings',
                    fn (Builder $readings): Builder => $readings
                        ->where('billing_period_id', $billingPeriodId)
                        ->where('consumption', '<', 0),
                )),
        ];
    }

    /**
     * Filters that narrow the walk itself. They are shared by the table and by
     * the header counters, so both always describe the same set of meters.
     *
     * @return array<int, BaseFilter>
     */
    private function scopeFilters(Organization $organization): array
    {
        return [
            ClientAddressFilter::make($organization, 'clients.region_id', 'clients.street_id'),
            ControllerZoneFilter::make($organization)
                ->visible(fn (): bool => OrganizationMemberAccess::canManageTenant()),
        ];
    }

    /**
     * @return Builder<Meter>
     */
    private function scopedProgressQuery(Organization $organization, User $user): Builder
    {
        $query = $this->baseMetersQuery($organization, $user);
        $filters = $this->scopeFilters($organization);
        $applied = $this->appliedTableFilters();

        foreach ($filters as $filter) {
            $filter->applyToBaseQuery($query, $applied[$filter->getName()] ?? []);
        }

        return $query->where(function (Builder $query) use ($filters, $applied): void {
            foreach ($filters as $filter) {
                $filter->apply($query, $applied[$filter->getName()] ?? []);
            }
        });
    }

    /**
     * Only filters the user has actually applied, never the pending state of a
     * deferred filter form.
     *
     * @return array<string, array<string, mixed>>
     */
    private function appliedTableFilters(): array
    {
        return array_filter(
            $this->tableFilters ?? [],
            fn (mixed $data): bool => is_array($data),
        );
    }

    /**
     * The security scope lives here and not in a filter: `$tableFilters` is a
     * public Livewire property the client can reset, while the base query is
     * rebuilt from the session on every request.
     *
     * @return Builder<Meter>
     */
    private function baseMetersQuery(Organization $organization, User $user): Builder
    {
        return Meter::query()
            ->select('meters.*')
            ->join('clients', 'clients.id', '=', 'meters.client_id')
            ->leftJoin('streets', 'streets.id', '=', 'clients.street_id')
            ->visibleToOrganizationMember($user, $organization)
            ->where('clients.status', 'active')
            // Meters of "per person" and "fixed" clients must never be read
            // here: their accrual ignores consumption, so a reading would make
            // the receipt and the accrual disagree.
            ->where('clients.billing_type', 'meter')
            ->where('meters.status', 'active');
    }

    /**
     * @return Builder<Meter>
     */
    private function metersQuery(Organization $organization, User $user, ?BillingPeriod $billingPeriod): Builder
    {
        return $this->baseMetersQuery($organization, $user)
            ->addSelect([
                'previous_reading_for_entry' => $this->previousReadingSubquery($billingPeriod),
            ])
            ->with([
                'client.region',
                'client.street',
                'readings' => fn (HasMany $query): HasMany => $query
                    ->where('billing_period_id', $billingPeriod?->getKey()),
            ])
            // Walking order of the zone. `length()` first, so house "2" sorts
            // before house "10" instead of after it.
            ->orderBy('streets.name')
            ->orderByRaw('length(clients.house), clients.house')
            ->orderByRaw('length(clients.apartment), clients.apartment')
            ->orderBy('meters.number');
    }

    /**
     * @return Builder<MeterReading>
     */
    private function previousReadingSubquery(?BillingPeriod $billingPeriod): Builder
    {
        return MeterReading::query()
            ->select('current_reading')
            ->whereColumn('meter_readings.meter_id', 'meters.id')
            // Strictly earlier periods only. Without this the column would show
            // the value that has just been typed and the consumption would
            // collapse to zero right after saving.
            ->when(
                $billingPeriod instanceof BillingPeriod,
                fn (Builder $query): Builder => $query->whereHas(
                    'billingPeriod',
                    fn (Builder $periodQuery): Builder => $periodQuery->whereDate(
                        'starts_on',
                        '<',
                        $billingPeriod->starts_on->toDateString(),
                    ),
                ),
            )
            ->orderByDesc(
                BillingPeriod::query()
                    ->select('starts_on')
                    ->whereColumn('billing_periods.id', 'meter_readings.billing_period_id'),
            )
            ->orderByDesc('id')
            ->limit(1);
    }

    /**
     * Writes one row. Reached through `updateTableColumnState`, which checks
     * neither policies nor anything the model throws, so both are handled here.
     */
    protected function saveReading(Meter $meter, mixed $state): mixed
    {
        $organization = $this->organization();
        $user = $this->currentUser();

        abort_unless($organization instanceof Organization, 404);
        abort_unless($user instanceof User, 403);
        abort_unless((int) $meter->organization_id === (int) $organization->getKey(), 403);
        abort_unless($meter->status === 'active', 403);
        abort_unless($meter->client?->status === 'active', 403);
        abort_unless($meter->client?->billing_type === 'meter', 403);
        abort_unless(OrganizationMemberAccess::canCreateMeterReadingForMeter($meter), 403);

        // Clearing the field must not delete the reading: deleting drops the
        // photo file for good and leaves the receipt of the period behind.
        if (blank($state)) {
            return null;
        }

        try {
            $billingPeriod = BillingPeriod::requireCurrentEditableFor($organization);
            $existing = $this->storedReading($meter, $billingPeriod->getKey());

            if ($existing instanceof MeterReading) {
                abort_unless(OrganizationMemberAccess::canUpdateMeterReading($existing), 403);

                $existing->update([
                    'current_reading' => $state,
                    'read_at' => $existing->read_at ?? today(),
                ]);

                $reading = $existing;
            } else {
                $reading = $meter->readings()->create([
                    // Always explicit: the model derives the previous reading
                    // before it resolves the period, so a missing period would
                    // make it read the latest reading of any period at all.
                    'billing_period_id' => $billingPeriod->getKey(),
                    'previous_reading' => MeterReading::previousReadingForBillingPeriod(
                        $meter->getKey(),
                        $billingPeriod->getKey(),
                    ),
                    'current_reading' => $state,
                    'read_at' => today(),
                ]);
            }
        } catch (ValidationException|QueryException $exception) {
            Notification::make()
                ->danger()
                ->title('Показание не сохранено')
                ->body($this->saveErrorMessage($exception))
                ->persistent()
                ->send();

            return null;
        }

        $this->warnAboutNegativeConsumption($meter, $reading);

        return $reading->current_reading;
    }

    private function warnAboutNegativeConsumption(Meter $meter, MeterReading $reading): void
    {
        if ((int) $reading->consumption >= 0) {
            return;
        }

        Notification::make()
            ->warning()
            ->title('Отрицательный расход')
            ->body("Счётчик {$meter->number}: расход {$reading->consumption}. Показание сохранено, но закрыть месяц с отрицательным расходом нельзя.")
            ->send();
    }

    private function saveErrorMessage(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            $message = $exception->validator->errors()->first();

            if (filled($message)) {
                return $message;
            }
        }

        return 'Не удалось сохранить показание. Обновите страницу и попробуйте ещё раз.';
    }

    private function detailsAction(): Action
    {
        return Action::make('details')
            ->label('Фото и примечание')
            ->icon(Heroicon::OutlinedCamera)
            ->color('gray')
            ->modalHeading(fn (Meter $record): string => "Показание счётчика {$record->number}")
            ->modalSubmitActionLabel('Сохранить')
            ->successNotificationTitle('Показание обновлено')
            ->visible(fn (Meter $record): bool => $this->canEnterReadings()
                && $this->currentBillingPeriod() instanceof BillingPeriod
                && $this->readingFor($record) instanceof MeterReading)
            ->fillForm(function (Meter $record): array {
                $reading = $this->readingFor($record);

                return [
                    'read_at' => $reading?->read_at?->toDateString(),
                    'note' => $reading?->note,
                    'photo_path' => $reading?->photo_path,
                ];
            })
            ->schema([
                DatePicker::make('read_at')
                    ->label('Дата снятия')
                    ->native(false),
                Textarea::make('note')
                    ->label('Примечание')
                    ->columnSpanFull(),
                MeterReadingForm::photoUpload(),
            ])
            ->action(function (Meter $record, array $data): void {
                $organization = $this->organization();

                abort_unless($organization instanceof Organization, 404);
                abort_unless((int) $record->organization_id === (int) $organization->getKey(), 403);

                try {
                    $billingPeriod = BillingPeriod::requireCurrentEditableFor($organization);
                    $reading = $this->storedReading($record, $billingPeriod->getKey());

                    abort_unless($reading instanceof MeterReading, 404);
                    abort_unless(OrganizationMemberAccess::canUpdateMeterReading($reading), 403);

                    $reading->update([
                        'read_at' => $data['read_at'] ?? null,
                        'note' => $data['note'] ?? null,
                        'photo_path' => $data['photo_path'] ?? null,
                    ]);
                } catch (ValidationException|QueryException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Показание не сохранено')
                        ->body($this->saveErrorMessage($exception))
                        ->persistent()
                        ->send();
                }
            });
    }

    /**
     * The zone is already enforced by the base query, so this only answers
     * "may this member enter readings at all" and costs two queries per
     * request instead of two per row.
     */
    private function canEnterReadings(): bool
    {
        return $this->canEnterReadingsCache ??= $this->resolveCanEnterReadings();
    }

    private function resolveCanEnterReadings(): bool
    {
        $organization = $this->organization();
        $user = $this->currentUser();

        if (! $organization instanceof Organization || ! $user instanceof User) {
            return false;
        }

        return $user->isOrganizationOperator($organization)
            || $user->isOrganizationController($organization);
    }

    private function readingFor(Meter $meter): ?MeterReading
    {
        $billingPeriodId = $this->currentBillingPeriod()?->getKey();

        if ($billingPeriodId === null) {
            return null;
        }

        if ($meter->relationLoaded('readings')) {
            /** @var Collection<int, MeterReading> $readings */
            $readings = $meter->readings;

            return $readings->firstWhere('billing_period_id', $billingPeriodId);
        }

        return $this->storedReading($meter, $billingPeriodId);
    }

    private function storedReading(Meter $meter, int $billingPeriodId): ?MeterReading
    {
        return MeterReading::query()
            ->where('meter_id', $meter->getKey())
            ->where('billing_period_id', $billingPeriodId)
            ->first();
    }

    private function hasNegativeConsumption(Meter $meter): bool
    {
        $reading = $this->readingFor($meter);

        return $reading instanceof MeterReading && (int) $reading->consumption < 0;
    }

    private function previousReading(Meter $meter): int
    {
        $reading = $this->readingFor($meter);

        if ($reading instanceof MeterReading) {
            return (int) $reading->previous_reading;
        }

        return MeterReading::wholeReading(
            $meter->getAttribute('previous_reading_for_entry') ?? $meter->initial_reading,
        ) ?? 0;
    }

    private function formatAddress(Meter $meter): string
    {
        $client = $meter->client;

        if (! $client) {
            return '-';
        }

        /** @var Collection<int, string> $parts */
        $parts = collect([
            $client->street?->name,
            filled($client->house) ? 'д. '.$client->house : null,
            filled($client->apartment) ? 'кв. '.$client->apartment : null,
        ])->filter(fn (?string $part): bool => filled($part));

        return $parts->isEmpty()
            ? ($client->region?->name ?? '-')
            : $parts->implode(', ');
    }

    private function currentBillingPeriod(): ?BillingPeriod
    {
        if ($this->billingPeriodResolved) {
            return $this->billingPeriodCache;
        }

        $this->billingPeriodResolved = true;

        return $this->billingPeriodCache = CurrentBillingPeriod::get($this->organization());
    }

    private function organization(): ?Organization
    {
        return OrganizationMemberAccess::tenant();
    }

    private function currentUser(): ?User
    {
        return OrganizationMemberAccess::user();
    }
}
