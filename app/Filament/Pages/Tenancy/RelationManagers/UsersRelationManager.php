<?php

namespace App\Filament\Pages\Tenancy\RelationManagers;

use App\Filament\Support\OrganizationMemberAccess;
use App\Models\Organization;
use App\Models\Region;
use App\Models\Street;
use App\Models\User;
use App\OrganizationMemberRole;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Unique;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Пользователи';

    protected static ?string $modelLabel = 'пользователь';

    protected static ?string $pluralModelLabel = 'пользователи';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return OrganizationMemberAccess::canManageTenant()
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function mount(): void
    {
        abort_unless(static::canViewForRecord($this->ownerRecord, $this->pageClass ?? static::class), 403);

        parent::mount();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->orderBy('name'))
            ->columns([
                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('membership_role')
                    ->label('Роль')
                    ->badge()
                    ->state(fn (User $record): string => $this->roleLabel($record)),
                TextColumn::make('controller_regions')
                    ->label('Регионы контроллера')
                    ->state(fn (User $record): string => $this->regionNamesFor($record))
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('controller_streets')
                    ->label('Улицы контроллера')
                    ->state(fn (User $record): string => $this->streetNamesFor($record))
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->headerActions([
                Action::make('attachUser')
                    ->label('Добавить пользователя')
                    ->modalHeading('Добавить существующего пользователя')
                    ->modalSubmitActionLabel('Добавить')
                    ->successNotificationTitle('Пользователь добавлен')
                    ->schema(fn (): array => [
                        Section::make('Пользователь')
                            ->columns(2)
                            ->schema([
                                Select::make('user_id')
                                    ->label('Пользователь')
                                    ->options(fn (): array => $this->availableUserOptions())
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->native(false),
                                ...$this->accessFormFields(),
                            ]),
                    ])
                    ->action(function (array $data): void {
                        $user = User::query()->findOrFail($data['user_id'] ?? null);
                        $role = $this->roleFromData($data);

                        DB::transaction(function () use ($user, $role, $data): void {
                            $this->ownerOrganization()->users()->attach($user, [
                                'role' => $role->value,
                            ]);

                            $this->syncControllerResponsibilities($user, $role, $data);
                        });
                    }),
                Action::make('createUser')
                    ->label('Создать пользователя')
                    ->modalHeading('Создать пользователя')
                    ->modalSubmitActionLabel('Создать')
                    ->successNotificationTitle('Пользователь создан')
                    ->schema(fn (): array => [
                        Section::make('Пользователь')
                            ->columns(2)
                            ->schema([
                                TextInput::make('name')
                                    ->label('Имя')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('email')
                                    ->label('Email')
                                    ->email()
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(
                                        table: 'users',
                                        column: 'email',
                                        modifyRuleUsing: fn (Unique $rule): Unique => $rule,
                                    ),
                                TextInput::make('password')
                                    ->label('Пароль')
                                    ->password()
                                    ->required()
                                    ->minLength(8)
                                    ->maxLength(255),
                                ...$this->accessFormFields(),
                            ]),
                    ])
                    ->action(function (array $data): void {
                        $role = $this->roleFromData($data);

                        DB::transaction(function () use ($data, $role): void {
                            $user = User::query()->create([
                                'name' => $data['name'],
                                'email' => $data['email'],
                                'password' => $data['password'],
                            ]);

                            $this->ownerOrganization()->users()->attach($user, [
                                'role' => $role->value,
                            ]);

                            $this->syncControllerResponsibilities($user, $role, $data);
                        });
                    }),
            ])
            ->recordActions([
                Action::make('editAccess')
                    ->label('Доступ')
                    ->modalHeading(fn (User $record): string => "Доступ пользователя: {$record->name}")
                    ->modalSubmitActionLabel('Сохранить')
                    ->successNotificationTitle('Доступ обновлён')
                    ->fillForm(fn (User $record): array => [
                        'role' => $this->roleFor($record)->value,
                        'region_ids' => $record->assignedRegionIdsForOrganization($this->ownerOrganization()),
                        'street_ids' => $record->assignedStreetIdsForOrganization($this->ownerOrganization()),
                    ])
                    ->schema(fn (): array => [
                        Section::make('Доступ в организации')
                            ->columns(2)
                            ->schema($this->accessFormFields()),
                    ])
                    ->action(function (User $record, array $data): void {
                        $role = $this->roleFromData($data);

                        DB::transaction(function () use ($record, $role, $data): void {
                            $this->ownerOrganization()->users()->updateExistingPivot($record->getKey(), [
                                'role' => $role->value,
                            ]);

                            $this->syncControllerResponsibilities($record, $role, $data);
                        });
                    }),
                Action::make('detachUser')
                    ->label('Исключить')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record): string => "Исключить пользователя {$record->name} из организации?")
                    ->modalDescription('Пользователь потеряет доступ к этой организации. Глобальная учётная запись удалена не будет.')
                    ->modalSubmitActionLabel('Исключить')
                    ->successNotificationTitle('Пользователь исключён')
                    ->visible(fn (User $record): bool => (int) $record->getKey() !== (int) auth()->id())
                    ->action(function (User $record): void {
                        DB::transaction(function () use ($record): void {
                            $this->clearResponsibilities($record);
                            $this->ownerOrganization()->users()->detach($record);
                        });
                    }),
            ]);
    }

    /**
     * @return array<int, Select>
     */
    private function accessFormFields(): array
    {
        return [
            Select::make('role')
                ->label('Роль')
                ->options(OrganizationMemberRole::class)
                ->default(OrganizationMemberRole::Operator->value)
                ->required()
                ->live()
                ->native(false),
            Select::make('region_ids')
                ->label('Регионы контроллера')
                ->helperText('Используется только для роли контроллера.')
                ->options(fn (): array => $this->regionOptions())
                ->multiple()
                ->searchable()
                ->preload()
                ->visible(fn (Get $get): bool => $this->roleValue($get('role')) === OrganizationMemberRole::Controller->value)
                ->native(false),
            Select::make('street_ids')
                ->label('Отдельные улицы контроллера')
                ->helperText('Используется только для роли контроллера.')
                ->options(fn (): array => $this->streetOptions())
                ->multiple()
                ->searchable()
                ->preload()
                ->visible(fn (Get $get): bool => $this->roleValue($get('role')) === OrganizationMemberRole::Controller->value)
                ->native(false),
        ];
    }

    private function ownerOrganization(): Organization
    {
        abort_unless($this->ownerRecord instanceof Organization, 404);

        return $this->ownerRecord;
    }

    /**
     * @return array<int, string>
     */
    private function availableUserOptions(): array
    {
        $organization = $this->ownerOrganization();

        return User::query()
            ->whereDoesntHave(
                'organizations',
                fn (Builder $query): Builder => $query->whereKey($organization->getKey()),
            )
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->id => "{$user->name} ({$user->email})",
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function regionOptions(): array
    {
        return $this->ownerOrganization()
            ->regions()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function streetOptions(): array
    {
        return Street::query()
            ->with('region')
            ->whereBelongsTo($this->ownerOrganization())
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Street $street): array => [
                $street->id => ($street->region?->name ? "{$street->region->name} / " : '').$street->name,
            ])
            ->all();
    }

    private function roleFromData(array $data): OrganizationMemberRole
    {
        $role = OrganizationMemberRole::tryFrom($this->roleValue($data['role'] ?? null));

        abort_unless($role instanceof OrganizationMemberRole, 422);

        return $role;
    }

    private function roleValue(mixed $role): string
    {
        if ($role instanceof OrganizationMemberRole) {
            return $role->value;
        }

        return is_string($role) ? $role : '';
    }

    private function roleFor(User $user): OrganizationMemberRole
    {
        return $user->organizationRole($this->ownerOrganization()) ?? OrganizationMemberRole::Operator;
    }

    private function roleLabel(User $user): string
    {
        return $this->roleFor($user)->getLabel() ?? $this->roleFor($user)->value;
    }

    private function syncControllerResponsibilities(User $user, OrganizationMemberRole $role, array $data): void
    {
        if ($role !== OrganizationMemberRole::Controller) {
            $this->clearResponsibilities($user);

            return;
        }

        $organization = $this->ownerOrganization();
        $timestamp = now();
        $regionIds = $this->validRegionIds($data['region_ids'] ?? []);
        $streetIds = $this->validStreetIds($data['street_ids'] ?? []);

        $this->clearResponsibilities($user);

        if ($regionIds !== []) {
            DB::table('organization_user_regions')->insert(array_map(
                fn (int $regionId): array => [
                    'organization_id' => $organization->getKey(),
                    'user_id' => $user->getKey(),
                    'region_id' => $regionId,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                $regionIds,
            ));
        }

        if ($streetIds !== []) {
            DB::table('organization_user_streets')->insert(array_map(
                fn (int $streetId): array => [
                    'organization_id' => $organization->getKey(),
                    'user_id' => $user->getKey(),
                    'street_id' => $streetId,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                $streetIds,
            ));
        }
    }

    private function clearResponsibilities(User $user): void
    {
        $organization = $this->ownerOrganization();

        DB::table('organization_user_regions')
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->delete();

        DB::table('organization_user_streets')
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->delete();
    }

    /**
     * @return list<int>
     */
    private function validRegionIds(mixed $regionIds): array
    {
        $regionIds = collect(is_array($regionIds) ? $regionIds : [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($regionIds->isEmpty()) {
            return [];
        }

        return Region::query()
            ->whereBelongsTo($this->ownerOrganization())
            ->whereKey($regionIds->all())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function validStreetIds(mixed $streetIds): array
    {
        $streetIds = collect(is_array($streetIds) ? $streetIds : [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($streetIds->isEmpty()) {
            return [];
        }

        return Street::query()
            ->whereBelongsTo($this->ownerOrganization())
            ->whereKey($streetIds->all())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function regionNamesFor(User $user): string
    {
        $regionIds = $user->assignedRegionIdsForOrganization($this->ownerOrganization());

        if ($regionIds === []) {
            return '-';
        }

        return Region::query()
            ->whereBelongsTo($this->ownerOrganization())
            ->whereKey($regionIds)
            ->orderBy('name')
            ->pluck('name')
            ->implode(', ');
    }

    private function streetNamesFor(User $user): string
    {
        $streetIds = $user->assignedStreetIdsForOrganization($this->ownerOrganization());

        if ($streetIds === []) {
            return '-';
        }

        return Street::query()
            ->with('region')
            ->whereBelongsTo($this->ownerOrganization())
            ->whereKey($streetIds)
            ->orderBy('name')
            ->get()
            ->map(fn (Street $street): string => ($street->region?->name ? "{$street->region->name} / " : '').$street->name)
            ->implode(', ');
    }
}
