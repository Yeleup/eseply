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

    /**
     * Values awaiting the controller's large-consumption confirmation.
     *
     * @var array<int, int>
     */
    public array $pendingReadings = [];

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
            ->headerActions([$this->largeConsumptionConfirmationAction()])
            ->recordActions([$this->detailsAction()])
            ->recordClasses(fn (Meter $record): array => array_filter([
                $this->hasNegativeConsumption($record) ? 'fi-readings-negative' : null,
                $this->hasPendingReading($record) ? 'fi-readings-pending' : null,
            ]))
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
                ->where('billing_period_id', $billingPeriodId)
                ->taken())
            ->count();

        $problem = (clone $query)
            ->whereHas('readings', fn (Builder $query): Builder => $query
                ->where('billing_period_id', $billingPeriodId)
                ->taken()
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
                // everything thrown deeper is swallowed by Livewire. The column
                // is bound to its record before the rules are evaluated, so the
                // minimum is the one of this very meter.
                ->rules(fn (Meter $record): array => [
                    'integer',
                    'min:'.$this->minimumReadingFor($record),
                    'max:'.MeterReading::MAXIMUM_CURRENT_READING,
                ])
                ->validationMessages([
                    'min' => fn (Meter $record): ?string => OrganizationMemberAccess::canEnterMeterReadingBelowPrevious()
                        ? null
                        : MeterReading::belowPreviousReadingMessage($this->previousReading($record)),
                    'max' => MeterReading::maximumCurrentReadingMessage(),
                ])
                ->getStateUsing(fn (Meter $record): ?int => $this->pendingReadings[$record->getKey()]
                    ?? $this->readingFor($record)?->current_reading)
                // Deliberately not tied to the billing period: `isDisabled()`
                // is re-evaluated on every save, so a period closing while the
                // page is open would turn the save into a silent no-op instead
                // of the error the controller has to see.
                ->disabled(fn (): bool => ! $this->canEnterReadings())
                ->updateStateUsing(fn (Meter $record, mixed $state): mixed => $this->saveReading($record, $state))
                ->extraInputAttributes(fn (Meter $record): array => [
                    'class' => $this->hasPendingReading($record)
                        ? 'fi-readings-input fi-readings-input-pending'
                        : 'fi-readings-input',
                    'title' => $this->hasPendingReading($record)
                        ? 'Показание не сохранено: подтвердите или измените значение.'
                        : null,
                ]),

            TextColumn::make('consumption_for_entry')
                ->label('Расход')
                // A visit without a reading consumed nothing measurable, so the
                // cell shows the placeholder instead of a green zero.
                ->state(fn (Meter $record): ?int => $this->takenReadingFor($record)?->consumption)
                ->badge()
                ->color(fn (?int $state): string => match (true) {
                    $state === null => 'gray',
                    $state < 0 => 'warning',
                    default => 'success',
                })
                ->placeholder('-'),

            TextColumn::make('read_at_for_entry')
                ->label('Снято')
                ->state(fn (Meter $record) => $this->takenReadingFor($record)?->read_at)
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
                    // A visit that took no reading leaves the meter unread, so
                    // the row has to stay on the walk list instead of vanishing
                    // behind the default filter.
                    fn (Builder $readings): Builder => $readings
                        ->where('billing_period_id', $billingPeriodId)
                        ->taken(),
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
            ->taken()
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
    protected function saveReading(Meter $meter, mixed $state, bool $hasConfirmedLargeConsumption = false): mixed
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
            $previousReading = $existing instanceof MeterReading && $existing->isTaken()
                ? (int) $existing->previous_reading
                : MeterReading::previousReadingForBillingPeriod(
                    $meter->getKey(),
                    $billingPeriod->getKey(),
                );

            $currentReading = MeterReading::wholeReading($state);
            $storedCurrentReading = $existing instanceof MeterReading
                ? MeterReading::wholeReading($existing->current_reading)
                : null;

            // The hidden confirmation action can be invoked without the
            // TextInputColumn validation, so retain the controller's lower
            // boundary here as well. An operator's existing negative row may
            // still be re-saved unchanged to attach a note or a photo.
            if ($user->isOrganizationController($organization)
                && $currentReading !== null
                && $currentReading < ($previousReading ?? 0)
                && $currentReading !== $storedCurrentReading) {
                throw ValidationException::withMessages([
                    'current_reading' => MeterReading::belowPreviousReadingMessage($previousReading),
                ]);
            }

            $largeConsumptionConfirmation = $user->isOrganizationController($organization)
                ? MeterReading::largeConsumptionConfirmationFor(
                    meterId: $meter->getKey(),
                    billingPeriodId: $billingPeriod->getKey(),
                    previousReading: $previousReading,
                    currentReading: $state,
                )
                : null;

            if (! $hasConfirmedLargeConsumption && $largeConsumptionConfirmation !== null) {
                $this->pendingReadings[$meter->getKey()] = $largeConsumptionConfirmation['current_reading'];

                $this->mountAction('confirmLargeConsumption', [
                    'meter_id' => (int) $meter->getKey(),
                    ...$largeConsumptionConfirmation,
                ], context: ['table' => true]);

                return null;
            }

            if ($existing instanceof MeterReading) {
                abort_unless(OrganizationMemberAccess::canUpdateMeterReading($existing), 403);

                $existing->update([
                    'current_reading' => $state,
                    'read_at' => $existing->read_at ?? today(),
                    // A row left by a visit without a reading may have been
                    // created before a late reading of an earlier period
                    // landed, so the baseline is resolved again rather than
                    // carried over from that visit.
                    'previous_reading' => $previousReading,
                ]);

                $reading = $existing;
            } else {
                $reading = $meter->readings()->create([
                    // Always explicit: the model derives the previous reading
                    // before it resolves the period, so a missing period would
                    // make it read the latest reading of any period at all.
                    'billing_period_id' => $billingPeriod->getKey(),
                    'previous_reading' => $previousReading,
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

        unset($this->pendingReadings[$meter->getKey()]);

        $this->warnAboutNegativeConsumption($meter, $reading);

        return $reading->current_reading;
    }

    private function largeConsumptionConfirmationAction(): Action
    {
        return Action::make('confirmLargeConsumption')
            ->extraAttributes(['class' => 'hidden'])
            ->requiresConfirmation()
            ->modalHeading(__('meter-readings.confirmation.large_consumption.heading'))
            ->modalDescription(fn (array $arguments): string => MeterReading::largeConsumptionConfirmationDescription([
                'current_reading' => (int) ($arguments['current_reading'] ?? 0),
                'consumption' => (int) ($arguments['consumption'] ?? 0),
                'average_consumption' => (float) ($arguments['average_consumption'] ?? 0),
            ]))
            ->modalSubmitActionLabel(__('meter-readings.confirmation.large_consumption.submit'))
            ->modalCancelActionLabel(__('meter-readings.confirmation.large_consumption.cancel'))
            ->action(function (array $arguments): void {
                $meter = Meter::query()->find($arguments['meter_id'] ?? null);

                abort_unless($meter instanceof Meter, 404);

                $this->saveReading(
                    $meter,
                    $arguments['current_reading'] ?? null,
                    hasConfirmedLargeConsumption: true,
                );
            });
    }

    /**
     * The lowest value this member may save for the meter: the previous reading
     * for a controller, zero for an operator.
     */
    private function minimumReadingFor(Meter $meter): int
    {
        return OrganizationMemberAccess::minimumMeterReading($this->previousReading($meter));
    }

    private function hasPendingReading(Meter $meter): bool
    {
        return array_key_exists($meter->getKey(), $this->pendingReadings);
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
            ->modalDescription('Если показание снять не удалось, оставьте фото и примечание без него: счётчик останется в списке не снятых.')
            ->modalSubmitActionLabel('Сохранить')
            ->successNotificationTitle('Показание обновлено')
            // Deliberately not tied to an existing reading. A controller who
            // reached a meter they could not read — sealed, no access, nobody
            // home — has to be able to record why, and that is exactly the case
            // where no reading row exists yet.
            ->visible(fn (): bool => $this->canEnterReadings()
                && $this->currentBillingPeriod() instanceof BillingPeriod)
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

                abort_unless($record->status === 'active', 403);
                abort_unless($record->client?->status === 'active', 403);
                abort_unless($record->client?->billing_type === 'meter', 403);

                $details = [
                    'read_at' => $data['read_at'] ?? null,
                    'note' => $data['note'] ?? null,
                    'photo_path' => $data['photo_path'] ?? null,
                ];

                try {
                    $billingPeriod = BillingPeriod::requireCurrentEditableFor($organization);
                    $reading = $this->storedReading($record, $billingPeriod->getKey());

                    if ($reading instanceof MeterReading) {
                        abort_unless(OrganizationMemberAccess::canUpdateMeterReading($reading), 403);

                        $reading->update($details);

                        return;
                    }

                    abort_unless(OrganizationMemberAccess::canCreateMeterReadingForMeter($record), 403);

                    // The row is created without a value: the visit happened,
                    // the reading did not. It stays "не снято" everywhere until
                    // somebody types a number into this very row.
                    $record->readings()->create([
                        ...$details,
                        'billing_period_id' => $billingPeriod->getKey(),
                        'previous_reading' => MeterReading::previousReadingForBillingPeriod(
                            $record->getKey(),
                            $billingPeriod->getKey(),
                        ),
                        'current_reading' => null,
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

    /**
     * The reading of the period only when it actually carries a value. A visit
     * that took no reading must read as "nothing here yet" to every column that
     * reports what the meter showed.
     */
    private function takenReadingFor(Meter $meter): ?MeterReading
    {
        $reading = $this->readingFor($meter);

        return $reading instanceof MeterReading && $reading->isTaken() ? $reading : null;
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
