<?php

namespace App\Filament\Resources\BillingPeriods\Pages;

use App\Filament\Resources\BillingPeriods\BillingPeriodResource;
use App\Filament\Resources\BillingPeriods\Tables\BillingPeriodClosureErrorsTable;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Reports\BillingPeriodClosureErrorsReport;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        return $this->getBillingPeriod()->failure_message ?? 'Закрытие завершилось ошибкой.';
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

    private function report(): BillingPeriodClosureErrorsReport
    {
        return app(BillingPeriodClosureErrorsReport::class);
    }
}
