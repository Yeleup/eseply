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
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Throwable;
use UnitEnum;

/**
 * Cash desk of the operator: one abonent at a time.
 *
 * The job here is not a sweep of a list but a queue of people at the window, so
 * the page is driven by a single search field. Every found abonent with a debt
 * gets an «Оплатить» button that opens the payment in a modal with the debt in
 * front of the operator while the amount is typed. The search and its results
 * stay in place after the payment, and the focus goes back to the search,
 * ready for the next person.
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

    private ?BillingPeriod $billingPeriodCache = null;

    private bool $billingPeriodResolved = false;

    private ?bool $canManageTenantCache = null;

    /**
     * @var array{term: string, results: Collection<int, Client>}|null
     */
    private ?array $searchResultsCache = null;

    /**
     * @var array<int, Client|null>
     */
    private array $paymentClientCache = [];

    public static function canAccess(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }

    public function mount(): void
    {
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Найдите абонента по лицевому счёту, фамилии или телефону, сверьте долг и примите оплату. Поиск остаётся на месте, а после сохранения курсор снова в поле поиска.';
    }

    /**
     * Abonents matching the search, each carrying the balance of the open period.
     *
     * The result is kept for the request: every row renders its own «Оплатить»
     * action, and the action looks its abonent up here instead of querying again.
     *
     * @return Collection<int, Client>
     */
    public function searchResults(): Collection
    {
        $term = trim($this->search);

        if (($this->searchResultsCache['term'] ?? null) === $term) {
            return $this->searchResultsCache['results'];
        }

        $this->searchResultsCache = [
            'term' => $term,
            'results' => $this->findClients($term),
        ];

        return $this->searchResultsCache['results'];
    }

    public function isSearchTooShort(): bool
    {
        $length = mb_strlen(trim($this->search));

        return $length > 0 && $length < self::SEARCH_MIN_LENGTH;
    }

    /**
     * Balance of an abonent for the open period.
     *
     * @return array{opening: float, accrued: float, paid: float, adjustment: float, debt: float, credit: float}
     */
    public function balanceOf(Client $client): array
    {
        if (! $this->hasBillingPeriod()) {
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

    /**
     * Whether the row offers «Оплатить»: only an abonent with a debt, and only
     * while a billing period is open. Read from the values the row already carries.
     */
    public function offersPayment(Client $client): bool
    {
        return $this->hasBillingPeriod()
            && $this->closingBalanceOf($client) > 0;
    }

    public function addressOf(Client $client): string
    {
        $address = collect([
            $client->street?->name,
            filled($client->house) ? 'д. '.$client->house : null,
            filled($client->apartment) ? 'кв. '.$client->apartment : null,
        ])->filter()->implode(', ');

        return $address !== '' ? $address : 'Адрес не указан';
    }

    public function payAction(): Action
    {
        return Action::make('pay')
            ->label('Оплатить')
            ->icon(Heroicon::OutlinedBanknotes)
            // Marks the modal for the page script that returns the cursor to the
            // search once the modal is closed by «Отмена», Esc or the cross.
            ->extraModalWindowAttributes(['data-payment-desk-pay' => true])
            // The abonent comes from the browser, so the action exists only for an
            // abonent the tenant-scoped query finds. The debt is not checked here:
            // a payment already typed in must reach the accept path and be either
            // saved or reported, never dropped because the debt changed meanwhile.
            ->visible(fn (array $arguments): bool => $this->paymentClient($arguments) instanceof Client)
            ->modalHeading(fn (array $arguments): string => $this->paymentHeading($this->paymentClient($arguments)))
            ->modalDescription(fn (array $arguments): ?Htmlable => $this->paymentDescription($this->paymentClient($arguments)))
            ->modalWidth(Width::TwoExtraLarge)
            ->mountUsing(function (Schema $schema, array $arguments, Action $action): void {
                $this->ensurePaymentIsOffered($this->paymentClient($arguments), $action);

                // Поле суммы всегда пустое: оператор вводит то, что реально принял.
                $schema->fill();
            })
            ->schema(fn (array $arguments): array => $this->paymentSchema($this->paymentClient($arguments)))
            ->modalSubmitActionLabel('Принять оплату')
            ->modalCancelActionLabel('Отмена')
            ->action(function (array $arguments, array $data, Action $action): void {
                $this->acceptPayment($arguments, $data, $action);
            });
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

    public function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ').' ₸';
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $data
     */
    private function acceptPayment(array $arguments, array $data, Action $action): void
    {
        $organization = $this->organization();
        $user = $this->currentUser();

        abort_unless($organization instanceof Organization, 404);
        abort_unless($user instanceof User, 403);
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);

        $client = $this->paymentClient($arguments);

        abort_unless($client instanceof Client, 404);

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

            // The modal stays open with the typed amount: the money is in hand
            // but not recorded, and the operator has to see that.
            $action->halt();

            return;
        }

        $accountNumber = $client->account_number;

        // Re-read the search and the abonent so the operator is told the
        // remainder, and the row shows the debt after the payment.
        $this->forgetSearchState();
        $client = $this->paymentClient($arguments);

        Notification::make()
            ->success()
            ->title('Оплата принята')
            ->body(sprintf(
                '%s — %s. %s',
                $accountNumber,
                $this->money((float) $payment->amount),
                $client instanceof Client ? $this->remainderSentence($this->balanceOf($client)) : '',
            ))
            ->send();

        $this->dispatch('payment-desk-reset');
    }

    /**
     * Abonent of the «Оплатить» action, resolved through the tenant-scoped query.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function paymentClient(array $arguments): ?Client
    {
        $clientId = $arguments['client'] ?? null;

        if (is_string($clientId) && ctype_digit($clientId)) {
            $clientId = (int) $clientId;
        }

        if (! is_int($clientId)) {
            return null;
        }

        if (array_key_exists($clientId, $this->paymentClientCache)) {
            return $this->paymentClientCache[$clientId];
        }

        $organization = $this->organization();
        $user = $this->currentUser();

        if (! $organization instanceof Organization || ! $user instanceof User || ! $this->canManageTenant()) {
            return $this->paymentClientCache[$clientId] = null;
        }

        // The rows of the search already carry the balance, so a list of buttons
        // costs no query; an abonent outside the list is looked up on its own.
        return $this->paymentClientCache[$clientId] = $this->searchResults()->find($clientId)
            ?? $this->clientsQuery($organization, $user)->whereKey($clientId)->first();
    }

    private function ensurePaymentIsOffered(?Client $client, Action $action): void
    {
        if (! $this->hasBillingPeriod()) {
            Notification::make()
                ->danger()
                ->title(CurrentBillingPeriod::MissingTitle)
                ->body(CurrentBillingPeriod::MissingDescription)
                ->send();

            $action->cancel();
        }

        // The row may be stale: another cashier could have taken the payment
        // while this page was open.
        if (! $client instanceof Client || ! $this->offersPayment($client)) {
            Notification::make()
                ->warning()
                ->title('Долга нет')
                ->body($client instanceof Client
                    ? sprintf('%s — %s', $client->account_number, $this->remainderSentence($this->balanceOf($client)))
                    : null)
                ->send();

            $action->cancel();
        }
    }

    private function paymentHeading(?Client $client): string
    {
        if (! $client instanceof Client) {
            return 'Приём оплаты';
        }

        return $client->account_number.' — '.$client->name;
    }

    private function paymentDescription(?Client $client): ?Htmlable
    {
        if (! $client instanceof Client) {
            return null;
        }

        $lines = [e($this->addressOf($client))];

        if (filled($label = $this->billingPeriodLabel())) {
            $lines[] = e('Расчётный месяц: '.$label);
        }

        return new HtmlString(implode('<br>', $lines));
    }

    /**
     * @return array<int, Component>
     */
    private function paymentSchema(?Client $client): array
    {
        $components = [];

        if ($client instanceof Client) {
            $components[] = View::make('filament.pages.payment-desk.client-summary')
                ->viewData(fn (): array => $this->clientSummary($client));
        }

        $components[] = Grid::make(['default' => 1, 'sm' => 2])
            ->schema([
                TextInput::make('amount')
                    ->label('Сумма')
                    ->numeric()
                    ->step('0.01')
                    // Оплата на ноль не операция, а опечатка.
                    ->minValue(0.01)
                    ->required()
                    ->autofocus()
                    ->suffixAction($this->fillFullDebtAction($client))
                    ->columnSpanFull(),
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
            ]);

        return $components;
    }

    /**
     * Card and balance of the abonent in the payment modal.
     *
     * Loaded for the one abonent of the modal only, never per search row.
     *
     * @return array{client: Client, address: string, serviceName: ?string, lastPayment: ?string, balance: array{opening: float, accrued: float, paid: float, adjustment: float, debt: float, credit: float}}
     */
    private function clientSummary(Client $client): array
    {
        $client->loadMissing('utilityService');

        $lastPayment = Payment::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->latest('paid_at')
            ->latest('id')
            ->first(['paid_at', 'amount', 'created_at']);

        return [
            'client' => $client,
            'address' => $this->addressOf($client),
            'serviceName' => $client->utilityService?->name,
            'lastPayment' => $lastPayment instanceof Payment
                ? sprintf(
                    '%s — %s',
                    ($lastPayment->paid_at ?? $lastPayment->created_at)?->format('d.m.Y'),
                    $this->money((float) $lastPayment->amount),
                )
                : null,
            'balance' => $this->balanceOf($client),
        ];
    }

    private function fillFullDebtAction(?Client $client): Action
    {
        $debt = $client instanceof Client ? $this->balanceOf($client)['debt'] : 0.0;

        return Action::make('fillFullDebt')
            ->label('Вся сумма')
            ->icon(Heroicon::OutlinedCalculator)
            ->color('gray')
            // Affix actions are icon buttons by default; the label tells the
            // cashier what the button does without a hover.
            ->link()
            ->visible($debt > 0)
            ->action(fn (Set $set) => $set('amount', number_format($debt, 2, '.', '')));
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
     * Payments recorded by an external payment provider mirror the provider's record,
     * so correcting them by hand here would split the two records.
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

    /**
     * @return Collection<int, Client>
     */
    private function findClients(string $term): Collection
    {
        // Пустой поиск — обычное состояние страницы между абонентами,
        // поэтому он не должен стоить запроса к базе.
        if (mb_strlen($term) < self::SEARCH_MIN_LENGTH) {
            return new Collection;
        }

        $organization = $this->organization();
        $user = $this->currentUser();

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

    private function forgetSearchState(): void
    {
        $this->searchResultsCache = null;
        $this->paymentClientCache = [];
    }

    /**
     * Every search row renders its own action, so the membership lookup behind
     * the tenant check is made once per request, not once per row.
     */
    private function canManageTenant(): bool
    {
        return $this->canManageTenantCache ??= OrganizationMemberAccess::canManageTenant();
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
