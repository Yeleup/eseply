<?php

namespace App\Filament\Pages;

use App\Filament\Support\CurrentBillingPeriod;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\PaymentMethod;
use App\Reports\TurnoverBalanceValues;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Throwable;
use UnitEnum;

/**
 * Cash desk of the operator: one abonent at a time.
 *
 * The job here is not a sweep of a list but a queue of people at the window, so
 * the page is driven by a single search field and keeps the debt of the found
 * abonent in front of the operator while the amount is typed. After a payment
 * the page resets itself and returns the focus to the search, ready for the
 * next person.
 *
 * @property-read Schema $form
 */
class PaymentDesk extends Page implements HasTable
{
    use InteractsWithTable;

    private const int SEARCH_LIMIT = 10;

    private const int SEARCH_MIN_LENGTH = 2;

    protected static ?string $slug = 'payment-desk';

    protected static ?string $title = 'Приём оплат';

    protected static ?string $navigationLabel = 'Приём оплат';

    protected static string|UnitEnum|null $navigationGroup = 'Учёт';

    protected static ?int $navigationSort = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected string $view = 'filament.pages.payment-desk';

    public string $search = '';

    public ?int $selectedClientId = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    private ?BillingPeriod $billingPeriodCache = null;

    private bool $billingPeriodResolved = false;

    private ?Client $selectedClientCache = null;

    private bool $selectedClientResolved = false;

    public static function canAccess(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }

    public function mount(): void
    {
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);

        $this->form->fill($this->blankPaymentData());
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Найдите абонента по лицевому счёту, фамилии или телефону, сверьте долг и примите оплату. После сохранения страница сама готова к следующему человеку.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Оплата')
                        ->columns(2)
                        ->schema([
                            TextInput::make('amount')
                                ->label('Сумма')
                                ->numeric()
                                ->step('0.01')
                                // Оплата на ноль не операция, а опечатка.
                                ->minValue(0.01)
                                ->required()
                                ->autofocus()
                                ->suffixAction($this->fillFullDebtAction()),
                            Select::make('method')
                                ->label('Способ оплаты')
                                ->options(PaymentMethod::class)
                                ->default(PaymentMethod::Cash->value)
                                ->required()
                                ->native(false),
                            DatePicker::make('paid_at')
                                ->label('Дата оплаты')
                                ->default(fn (): string => today()->toDateString())
                                ->required()
                                ->native(false),
                            Textarea::make('note')
                                ->label('Примечание')
                                ->columnSpanFull(),
                        ]),
                ])
                    ->livewireSubmitHandler('acceptPayment')
                    ->footer([
                        Actions::make([
                            Action::make('acceptPayment')
                                ->label('Принять оплату')
                                ->icon(Heroicon::OutlinedCheckCircle)
                                ->submit('acceptPayment')
                                ->keyBindings(['mod+enter'])
                                ->disabled(fn (): bool => ! $this->canAcceptPayment()),
                            Action::make('cancelClient')
                                ->label('Отменить')
                                ->color('gray')
                                ->action('clearClient'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Abonents matching the search, each carrying the balance of the open period.
     *
     * @return Collection<int, Client>
     */
    public function searchResults(): Collection
    {
        $term = trim($this->search);
        $organization = $this->organization();
        $user = $this->currentUser();

        // Пустой поиск — обычное состояние страницы между абонентами,
        // поэтому он не должен стоить запроса к базе.
        if (mb_strlen($term) < self::SEARCH_MIN_LENGTH) {
            return new Collection;
        }

        if (! $organization instanceof Organization || ! $user instanceof User) {
            return new Collection;
        }

        return $this->clientsQuery($organization, $user)
            ->where(function (Builder $query) use ($term): void {
                $query
                    ->where('clients.account_number', 'like', '%'.$term.'%')
                    ->orWhere('clients.name', 'like', '%'.$term.'%')
                    ->orWhere('clients.phone', 'like', '%'.$term.'%');
            })
            ->orderByRaw('case when clients.account_number = ? then 0 else 1 end', [$term])
            ->orderBy('clients.account_number')
            ->limit(self::SEARCH_LIMIT)
            ->get();
    }

    public function selectClient(int $clientId): void
    {
        // `selectedClientId` is a public Livewire property, so the identifier is
        // resolved through the tenant-scoped query and dropped when it misses.
        $this->selectedClientId = $clientId;
        $this->resetSelectedClientCache();

        if (! $this->selectedClient() instanceof Client) {
            $this->selectedClientId = null;

            return;
        }

        $this->search = '';
        $this->form->fill($this->blankPaymentData());
    }

    public function clearClient(): void
    {
        $this->selectedClientId = null;
        $this->resetSelectedClientCache();
        $this->search = '';
        $this->form->fill($this->blankPaymentData());

        $this->dispatch('payment-desk-reset');
    }

    public function selectedClient(): ?Client
    {
        if ($this->selectedClientResolved) {
            return $this->selectedClientCache;
        }

        $this->selectedClientResolved = true;

        $organization = $this->organization();
        $user = $this->currentUser();

        if ($this->selectedClientId === null || ! $organization instanceof Organization || ! $user instanceof User) {
            return $this->selectedClientCache = null;
        }

        return $this->selectedClientCache = $this->clientsQuery($organization, $user)
            ->whereKey($this->selectedClientId)
            ->first();
    }

    /**
     * Balance of the selected abonent for the open period.
     *
     * @return array{opening: float, accrued: float, paid: float, adjustment: float, debt: float, credit: float}
     */
    public function balance(): array
    {
        $client = $this->selectedClient();

        if (! $client instanceof Client || ! $this->hasBillingPeriod()) {
            return ['opening' => 0.0, 'accrued' => 0.0, 'paid' => 0.0, 'adjustment' => 0.0, 'debt' => 0.0, 'credit' => 0.0];
        }

        $metrics = TurnoverBalanceValues::metricsOf($client);

        // `max()` в metrics() возвращает int, когда выигрывает литеральный ноль,
        // поэтому тип приводится здесь, а не оставляется на усмотрение PHP.
        return [
            'opening' => (float) $metrics['opening_debit'] - (float) $metrics['opening_credit'],
            'accrued' => (float) $metrics['accrued_amount'],
            'paid' => (float) $metrics['paid_amount'],
            'adjustment' => (float) $metrics['adjustment_amount'],
            'debt' => (float) $metrics['closing_debit'],
            'credit' => (float) $metrics['closing_credit'],
        ];
    }

    /**
     * Signed closing balance of a search row: positive is debt, negative is credit.
     */
    public function closingBalanceOf(Client $client): float
    {
        if (! $this->hasBillingPeriod()) {
            return 0.0;
        }

        $metrics = TurnoverBalanceValues::metricsOf($client);

        return (float) $metrics['closing_debit'] - (float) $metrics['closing_credit'];
    }

    public function fillFullDebt(): void
    {
        $debt = $this->balance()['debt'];

        if ($debt <= 0) {
            return;
        }

        $this->data['amount'] = number_format($debt, 2, '.', '');
    }

    public function acceptPayment(): void
    {
        $organization = $this->organization();
        $user = $this->currentUser();

        abort_unless($organization instanceof Organization, 404);
        abort_unless($user instanceof User, 403);
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);

        $client = $this->selectedClient();

        if (! $client instanceof Client) {
            Notification::make()
                ->danger()
                ->title('Абонент не выбран')
                ->body('Найдите абонента по лицевому счёту, фамилии или телефону.')
                ->send();

            return;
        }

        // Outside the try: form validation must reach the fields, not a notification.
        $data = $this->form->getState();

        try {
            $billingPeriod = BillingPeriod::requireCurrentEditableFor($organization);

            $payment = Payment::query()->create([
                'organization_id' => $organization->getKey(),
                'client_id' => $client->getKey(),
                'billing_period_id' => $billingPeriod->getKey(),
                'method' => $data['method'] ?? PaymentMethod::Cash->value,
                'received_by_user_id' => $user->getKey(),
                'amount' => $data['amount'],
                'paid_at' => $data['paid_at'] ?? today(),
                'note' => $data['note'] ?? null,
            ]);
        } catch (ValidationException|QueryException $exception) {
            Notification::make()
                ->danger()
                ->title('Оплата не принята')
                ->body($this->saveErrorMessage($exception))
                ->persistent()
                ->send();

            return;
        }

        $accountNumber = $client->account_number;
        $amount = (float) $payment->amount;

        // Re-read the balance so the operator is told the remainder, not the
        // number that was on screen before the payment landed.
        $this->resetSelectedClientCache();
        $remaining = $this->balance();

        Notification::make()
            ->success()
            ->title('Оплата принята')
            ->body(sprintf(
                '%s — %s. %s',
                $accountNumber,
                $this->money($amount),
                $this->remainderSentence($remaining),
            ))
            ->send();

        $this->clearClient();
    }

    public function table(Table $table): Table
    {
        $organization = $this->organization();

        abort_unless($organization instanceof Organization, 404);

        return $table
            ->query(
                Payment::query()
                    ->where('payments.organization_id', $organization->getKey())
                    ->whereDate('payments.created_at', today())
                    ->with(['client', 'receivedByUser', 'billingPeriod'])
            )
            ->defaultSort('payments.id', 'desc')
            ->heading('Принято сегодня')
            ->description('Все оплаты организации за сегодняшний день, свежие сверху.')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Время')
                    ->dateTime('H:i'),
                TextColumn::make('client.account_number')
                    ->label('Лицевой счёт')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('client', fn (Builder $query): Builder => $query
                            ->where('account_number', 'like', '%'.$search.'%'))),
                TextColumn::make('client.name')
                    ->label('Абонент')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('client', fn (Builder $query): Builder => $query
                            ->where('name', 'like', '%'.$search.'%'))),
                TextColumn::make('amount')
                    ->label('Сумма')
                    ->money('KZT')
                    ->weight('bold'),
                TextColumn::make('method')
                    ->label('Способ')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): ?string => PaymentMethod::labelFor($state))
                    ->color(fn (mixed $state): string => PaymentMethod::colorFor($state)),
                TextColumn::make('receivedByUser.name')
                    ->label('Принял')
                    ->placeholder('-'),
                TextColumn::make('note')
                    ->label('Примечание')
                    ->placeholder('-')
                    ->wrap()
                    ->toggleable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Исправить')
                    ->modalHeading('Исправить оплату')
                    ->visible(fn (Payment $record): bool => $this->canCorrect($record))
                    ->schema($this->correctionSchema())
                    ->using(fn (Payment $record, array $data): Payment => $this->applyCorrection($record, $data)),
                DeleteAction::make()
                    ->label('Удалить')
                    ->modalHeading('Удалить оплату?')
                    ->modalDescription('Оплата будет удалена, а сальдо абонента пересчитано.')
                    ->visible(fn (Payment $record): bool => $this->canCorrect($record))
                    ->using(function (Payment $record): void {
                        abort_unless($this->canCorrect($record), 403);

                        try {
                            $record->delete();
                        } catch (ValidationException|QueryException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Оплата не удалена')
                                ->body($this->saveErrorMessage($exception))
                                ->persistent()
                                ->send();
                        }
                    }),
            ])
            ->recordUrl(null)
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Сегодня оплат ещё не было')
            ->emptyStateDescription('Принятые оплаты появятся здесь сразу после сохранения.');
    }

    /**
     * @return array{count: int, amount: float}
     */
    public function todayTotals(): array
    {
        $organization = $this->organization();

        if (! $organization instanceof Organization) {
            return ['count' => 0, 'amount' => 0.0];
        }

        $query = Payment::query()
            ->where('organization_id', $organization->getKey())
            ->whereDate('created_at', today());

        return [
            'count' => (clone $query)->count(),
            'amount' => (float) (clone $query)->sum('amount'),
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

    public function canAcceptPayment(): bool
    {
        return $this->hasBillingPeriod()
            && $this->selectedClient() instanceof Client;
    }

    public function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ').' ₸';
    }

    /**
     * @param  array{debt: float, credit: float}  $balance
     */
    private function remainderSentence(array $balance): string
    {
        if ($balance['credit'] > 0) {
            return 'Переплата: '.$this->money($balance['credit']).'.';
        }

        if ($balance['debt'] > 0) {
            return 'Остаток долга: '.$this->money($balance['debt']).'.';
        }

        return 'Долга нет.';
    }

    /**
     * Kaspi payments arrive through the XPayment webhook and are reconciled with
     * the provider, so correcting them by hand here would split the two records.
     */
    private function canCorrect(Payment $payment): bool
    {
        return OrganizationMemberAccess::canManageTenant()
            && blank($payment->external_payment_id)
            && blank($payment->external_provider)
            && ($payment->billingPeriod?->isEditable() ?? false);
    }

    /**
     * @return array<int, Section>
     */
    private function correctionSchema(): array
    {
        return [
            Section::make('Оплата')
                ->columns(2)
                ->schema([
                    TextInput::make('amount')
                        ->label('Сумма')
                        ->numeric()
                        ->step('0.01')
                        ->minValue(0.01)
                        ->required(),
                    Select::make('method')
                        ->label('Способ оплаты')
                        ->options(PaymentMethod::class)
                        ->required()
                        ->native(false),
                    DatePicker::make('paid_at')
                        ->label('Дата оплаты')
                        ->native(false),
                    Textarea::make('note')
                        ->label('Примечание')
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyCorrection(Payment $payment, array $data): Payment
    {
        abort_unless($this->canCorrect($payment), 403);

        try {
            $payment->update([
                'amount' => $data['amount'],
                'method' => $data['method'],
                'paid_at' => $data['paid_at'] ?? null,
                'note' => $data['note'] ?? null,
            ]);
        } catch (ValidationException|QueryException $exception) {
            Notification::make()
                ->danger()
                ->title('Оплата не исправлена')
                ->body($this->saveErrorMessage($exception))
                ->persistent()
                ->send();
        }

        return $payment;
    }

    private function fillFullDebtAction(): Action
    {
        return Action::make('fillFullDebt')
            ->label('Вся сумма')
            ->icon(Heroicon::OutlinedCalculator)
            ->color('gray')
            ->action('fillFullDebt')
            ->visible(fn (): bool => $this->balance()['debt'] > 0);
    }

    /**
     * @return Builder<Client>
     */
    private function clientsQuery(Organization $organization, User $user): Builder
    {
        $query = Client::query()
            ->select('clients.*')
            ->with(['region', 'street'])
            ->visibleToOrganizationMember($user, $organization);

        $billingPeriod = $this->currentBillingPeriod();

        if ($billingPeriod instanceof BillingPeriod) {
            // The same engine the turnover balance sheet uses, so the debt at the
            // desk and the debt in the report can never disagree. It also covers
            // abonents without a receipt, whose balance a receipt lookup misses.
            $query->addSelect(TurnoverBalanceValues::openPeriodSubQueries($organization, $billingPeriod));
        }

        return $query;
    }

    /**
     * @return array{amount: null, method: string, paid_at: string, note: null}
     */
    private function blankPaymentData(): array
    {
        return [
            'amount' => null,
            'method' => PaymentMethod::Cash->value,
            'paid_at' => today()->toDateString(),
            'note' => null,
        ];
    }

    private function saveErrorMessage(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            $message = $exception->validator->errors()->first();

            if (filled($message)) {
                return $message;
            }
        }

        return 'Не удалось сохранить оплату. Обновите страницу и попробуйте ещё раз.';
    }

    private function resetSelectedClientCache(): void
    {
        $this->selectedClientCache = null;
        $this->selectedClientResolved = false;
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
