<?php

namespace App\Filament\Resources\BillingPeriods\Pages;

use App\Actions\AcceptBillingClosureMeterReadings;
use App\Actions\CloseBillingMonth;
use App\BillingPeriodStatus;
use App\Filament\Resources\BillingPeriods\BillingPeriodResource;
use App\Filament\Resources\BillingPeriods\Tables\BillingPeriodClosureErrorsTable;
use App\Filament\Support\OrganizationMemberAccess;
use App\Jobs\CloseBillingMonthJob;
use App\Models\BillingPeriod;
use App\Reports\BillingPeriodClosureErrorsReport;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ListBillingPeriodClosureErrors extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = BillingPeriodResource::class;

    protected string $view = 'filament.billing-periods.closure-errors';

    public function mount(int|string $record): void
    {
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);

        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        return "Ошибки закрытия: {$this->getBillingPeriod()->label}";
    }

    public function getBreadcrumb(): string
    {
        return 'Отчёт ошибок';
    }

    public function getSubheading(): string|Htmlable|null
    {
        $period = $this->getBillingPeriod();

        return match ($period->status) {
            BillingPeriodStatus::Closed => 'Месяц закрыт.',
            BillingPeriodStatus::Processing => 'Выполняется закрытие месяца. Результат появится автоматически.',
            BillingPeriodStatus::Failed => $period->failure_message ?? 'Закрытие завершилось ошибкой.',
            default => 'Месяц открыт.',
        };
    }

    public function table(Table $table): Table
    {
        return BillingPeriodClosureErrorsTable::configure($table, $this->getBillingPeriod());
    }

    public function downloadExcel(): StreamedResponse
    {
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);

        return $this->report()->downloadExcel($this->getBillingPeriod());
    }

    public function acceptReadings(string $code, ?int $errorId = null): void
    {
        $this->authorizePeriodManagement();
        $period = $this->getBillingPeriod();
        $meterId = null;

        if ($errorId !== null) {
            $error = $period->closureErrors()->where('organization_id', $period->organization_id)->findOrFail($errorId);
            abort_unless($error->code === $code && isset($error->context['meter_id']), 422);
            $meterId = (int) $error->context['meter_id'];
            abort_unless($meterId > 0, 422);
        }

        $count = app(AcceptBillingClosureMeterReadings::class)->handle(
            OrganizationMemberAccess::tenant(),
            $period,
            OrganizationMemberAccess::user(),
            $code,
            $meterId,
        );

        $this->resetTable();
        Notification::make()->title("Принято показаний: {$count}")
            ->body('Запустите закрытие месяца для повторной проверки всех данных.')
            ->success()->send();
    }

    public function closeBillingMonth(): void
    {
        $this->authorizePeriodManagement();
        $tenant = OrganizationMemberAccess::tenant();
        $operator = OrganizationMemberAccess::user();

        try {
            $period = app(CloseBillingMonth::class)->claim($tenant, $this->getBillingPeriod(), $operator);
        } catch (InvalidArgumentException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        try {
            CloseBillingMonthJob::dispatch($tenant, $period, $operator);
        } catch (Throwable $exception) {
            if ($period->refresh()->status === BillingPeriodStatus::Processing) {
                $period->markFailed(
                    ['active' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0],
                    'Не удалось поставить закрытие расчётного месяца в очередь.',
                );
            }

            throw $exception;
        }

        $this->getBillingPeriod()->refresh();
        $this->resetTable();
        Notification::make()->title('Закрытие месяца запущено')
            ->body('Результат придёт в уведомления после завершения расчёта.')
            ->info()->send();
    }

    private function authorizePeriodManagement(): void
    {
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);
        abort_unless($this->getBillingPeriod()->organization_id === OrganizationMemberAccess::tenant()?->id, 403);
    }

    public function getBillingPeriod(): BillingPeriod
    {
        $billingPeriod = $this->getRecord();

        abort_unless($billingPeriod instanceof BillingPeriod, 404);

        return $billingPeriod;
    }

    /**
     * @return Collection<int, array{code: string, label: string, total: int}>
     */
    public function getCodeSummary(): Collection
    {
        return $this->report()->codeSummary($this->getBillingPeriod());
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('acceptMissingReadings')
                ->label('Принять пропущенные показания')
                ->color('warning')
                ->visible(fn (): bool => $this->hasClosureErrors(AcceptBillingClosureMeterReadings::MISSING))
                ->requiresConfirmation()
                ->modalDescription('Для всех активных счётчиков без показания в этом месяце будет перенесено последнее известное значение с нулевым расходом. Фото и примечания сохранятся. Действие применяется ко всему месяцу независимо от фильтров таблицы.')
                ->action(fn () => $this->acceptReadings(AcceptBillingClosureMeterReadings::MISSING)),
            Action::make('acceptNegativeConsumptions')
                ->label('Принять отрицательный расход')
                ->color('warning')
                ->visible(fn (): bool => $this->hasClosureErrors(AcceptBillingClosureMeterReadings::NEGATIVE))
                ->requiresConfirmation()
                ->modalDescription('Вы подтверждаете ошибку по всем активным счётчикам с отрицательным расходом за этот месяц. Показания и расход останутся как есть; отрицательный расход уменьшит начисление при закрытии. Действие применяется ко всему месяцу независимо от фильтров таблицы.')
                ->action(fn () => $this->acceptReadings(AcceptBillingClosureMeterReadings::NEGATIVE)),
            Action::make('closeBillingMonth')
                ->label('Закрыть месяц')
                ->icon(Heroicon::OutlinedCalculator)
                ->disabled(fn (): bool => ! $this->getBillingPeriod()->isEditable())
                ->action(fn () => $this->closeBillingMonth()),
            Action::make('downloadExcel')
                ->label('Скачать Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('success')
                ->action(fn (): StreamedResponse => $this->downloadExcel()),
            Action::make('backToBillingPeriods')
                ->label('Расчётные месяцы')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(BillingPeriodResource::getUrl()),
        ];
    }

    private function hasClosureErrors(string $code): bool
    {
        return $this->getBillingPeriod()->isEditable()
            && $this->report()->query($this->getBillingPeriod())->where('code', $code)->exists();
    }

    private function report(): BillingPeriodClosureErrorsReport
    {
        return app(BillingPeriodClosureErrorsReport::class);
    }
}
