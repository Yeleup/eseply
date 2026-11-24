<?php

namespace App\Filament\Pages\Tenancy;

use App\Filament\Pages\Tenancy\RelationManagers\CitiesRelationManager;
use App\Filament\Pages\Tenancy\RelationManagers\UsersRelationManager;
use App\Filament\Resources\KaspiOperations\KaspiOperationResource;
use App\Filament\Support\OrganizationMemberAccess;
use App\Kaspi\KaspiActivity;
use App\Models\BillingPeriod;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class EditOrganizationProfile extends EditTenantProfile
{
    public const string KASPI_NEEDS_OPEN_MONTH = 'Включить приём можно, только когда у организации есть расчётный месяц «Открыт» или «Ошибка закрытия».';

    /**
     * @var array{line: string, color: string}|null
     */
    private ?array $kaspiActivityCache = null;

    public static function getLabel(): string
    {
        return 'Профиль организации';
    }

    public static function canView(Model $tenant): bool
    {
        $user = auth()->user();

        return $tenant instanceof Organization
            && $user instanceof User
            && $user->canManageOrganization($tenant);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
                Livewire::make(UsersRelationManager::class, fn (): array => [
                    'ownerRecord' => $this->tenant,
                    'pageClass' => static::class,
                ])
                    ->key('organization-users')
                    ->columnSpanFull(),
                Livewire::make(CitiesRelationManager::class, fn (): array => [
                    'ownerRecord' => $this->tenant,
                    'pageClass' => static::class,
                ])
                    ->key('organization-cities')
                    ->columnSpanFull(),
            ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Организация')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Название организации')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('bin_iin')
                            ->label('БИН / ИИН')
                            ->maxLength(12),
                        TextInput::make('phone')
                            ->label('Телефон')
                            ->tel()
                            ->maxLength(255),
                        TextInput::make('address')
                            ->label('Адрес')
                            ->maxLength(255),
                        TextInput::make('bank')
                            ->label('Банк')
                            ->maxLength(255),
                        TextInput::make('iban')
                            ->label('IBAN')
                            ->maxLength(34),
                        Textarea::make('note')
                            ->label('Примечание')
                            ->columnSpanFull(),
                    ]),
                Section::make('Услуга организации')
                    ->columns(2)
                    ->schema([
                        TextInput::make('utility_service_name')
                            ->label('Услуга')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('utility_service_unit_of_measurement')
                            ->label('Единица измерения')
                            ->required()
                            ->maxLength(255),
                    ]),
                $this->kaspiSection(),
            ]);
    }

    /**
     * Kaspi online of the organization: the switch, the permanent address and
     * the account format to give the Kaspi manager, and the last request.
     */
    private function kaspiSection(): Section
    {
        return Section::make('Kaspi онлайн')
            ->description('Через этот адрес Kaspi проверяет абонента и проводит оплату. При подключении передайте менеджеру Kaspi адрес и формат лицевого счёта.')
            ->columns(2)
            ->columnSpanFull()
            ->visible(fn (): bool => OrganizationMemberAccess::canManageTenant())
            ->schema([
                Toggle::make('kaspi_enabled')
                    ->label('Принимать оплаты Kaspi онлайн')
                    ->helperText('Если выключить, новые оплаты не принимаются (Kaspi получит код 5); уже принятые проведутся.')
                    ->rules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if ($value && ! $this->canEnableKaspi()) {
                                $fail(self::KASPI_NEEDS_OPEN_MONTH);
                            }
                        },
                    ])
                    ->columnSpanFull(),
                TextEntry::make('kaspi_address')
                    ->label('Адрес для Kaspi')
                    ->state(fn (): ?string => $this->organization()?->kaspiAddress())
                    ->copyable()
                    ->copyMessage('Адрес скопирован')
                    ->helperText('Адрес у каждой организации свой и не меняется. Запросы принимаются только с адреса Kaspi '.implode(', ', (array) config('kaspi.allowed_ips')).'.')
                    ->columnSpanFull(),
                TextEntry::make('kaspi_account_pattern')
                    ->label('Формат лицевого счёта (регулярное выражение)')
                    ->state((string) config('kaspi.account_pattern'))
                    ->copyable()
                    ->copyMessage('Выражение скопировано')
                    ->fontFamily('mono')
                    ->helperText('Kaspi проверяет номер по этому выражению ещё до запроса к нам.'),
                TextEntry::make('kaspi_format')
                    ->label('Формат ответа')
                    ->state('JSON, UTF-8'),
                TextEntry::make('kaspi_activity')
                    ->hiddenLabel()
                    ->state(fn (): string => $this->kaspiActivityLine())
                    ->color(fn (): string => $this->kaspiActivityColor())
                    ->icon(fn (): Heroicon => match ($this->kaspiActivityColor()) {
                        'warning' => Heroicon::OutlinedExclamationTriangle,
                        'success' => Heroicon::OutlinedCheckCircle,
                        default => Heroicon::OutlinedClock,
                    })
                    ->url(fn (): ?string => $this->kaspiActivityColor() === 'warning'
                        ? KaspiOperationResource::getUrl('index')
                        : null)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Acceptance is turned on only with a month a payment can go to; turning it
     * off, or saving it on as it was, is always allowed.
     */
    private function canEnableKaspi(): bool
    {
        $organization = $this->organization();

        if (! $organization instanceof Organization) {
            return false;
        }

        return $organization->kaspi_enabled
            || BillingPeriod::currentEditableFor($organization) instanceof BillingPeriod;
    }

    private function kaspiActivityLine(): string
    {
        return $this->kaspiActivity()['line'];
    }

    private function kaspiActivityColor(): string
    {
        return $this->kaspiActivity()['color'];
    }

    /**
     * Grey before the first request, green without errors, yellow with errors
     * in the last 24 hours and a link to the journal.
     *
     * @return array{line: string, color: string}
     */
    private function kaspiActivity(): array
    {
        if ($this->kaspiActivityCache !== null) {
            return $this->kaspiActivityCache;
        }

        $organization = $this->organization();
        $lastRequestAt = $organization instanceof Organization ? KaspiActivity::lastRequestAt($organization) : null;

        if (! $organization instanceof Organization || $lastRequestAt === null) {
            return $this->kaspiActivityCache = ['line' => 'Запросов от Kaspi ещё не было.', 'color' => 'gray'];
        }

        $errors = KaspiActivity::errorsInLastDay($organization);
        $line = 'Последний запрос от Kaspi: '.KaspiActivity::formatMoment($lastRequestAt).'. ';

        return $this->kaspiActivityCache = $errors > 0
            ? ['line' => $line."Ошибок за сутки: {$errors} — откройте журнал «Kaspi онлайн».", 'color' => 'warning']
            : ['line' => $line.'Ошибок за сутки нет.', 'color' => 'success'];
    }

    private function organization(): ?Organization
    {
        return $this->tenant instanceof Organization ? $this->tenant : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $organization = $this->tenant;

        if ($organization instanceof Organization) {
            $data['utility_service_name'] = $organization->utilityService?->name;
            $data['utility_service_unit_of_measurement'] = $organization->utilityService?->unit_of_measurement;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Organization, 404);

        $user = auth()->user();
        abort_unless($user instanceof User && $user->canManageOrganization($record), 403);

        // Not mass assignable: the switch is saved here, the uid never.
        $kaspiEnabled = (bool) ($data['kaspi_enabled'] ?? $record->kaspi_enabled);
        unset($data['kaspi_enabled'], $data['kaspi_uid']);

        $utilityServiceData = [
            'name' => $data['utility_service_name'],
            'unit_of_measurement' => $data['utility_service_unit_of_measurement'],
            'status' => 'active',
        ];

        unset($data['utility_service_name'], $data['utility_service_unit_of_measurement']);

        $record->update($data);
        $record->forceFill(['kaspi_enabled' => $kaspiEnabled])->save();

        $record->utilityService()->updateOrCreate(
            ['organization_id' => $record->id],
            $utilityServiceData,
        );

        return $record;
    }
}
