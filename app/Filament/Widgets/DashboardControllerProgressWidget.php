<?php

namespace App\Filament\Widgets;

use App\Dashboard\DashboardMetrics;
use App\Filament\Support\DashboardBillingPeriod;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

class DashboardControllerProgressWidget extends TableWidget
{
    use InteractsWithPageFilters;

    /**
     * Rows shown on the dashboard; the full list lives in the report.
     */
    private const int ROW_LIMIT = 10;

    protected int|string|array $columnSpan = 2;

    protected static ?int $sort = 4;

    public static function canView(): bool
    {
        return OrganizationMemberAccess::canAccessTenant();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Прогресс снятия по контроллерам')
            ->records(fn (): array => $this->records())
            ->columns([
                TextColumn::make('name')
                    ->label('Контроллер')
                    ->wrap(),
                TextColumn::make('total')
                    ->label('Всего счётчиков')
                    ->numeric(),
                TextColumn::make('taken')
                    ->label('Снято')
                    ->numeric(),
                TextColumn::make('missing')
                    ->label('Не снято')
                    ->numeric(),
                TextColumn::make('percent_label')
                    ->label('Процент снятия')
                    ->badge()
                    /** Array records reach the closure as plain arrays, so the parameter stays untyped. */
                    ->color(fn ($record): string => (string) $record['percent_color']),
            ])
            ->recordUrl(null)
            ->paginated(false)
            ->emptyStateHeading('Нет контроллеров')
            ->emptyStateDescription('В организации нет пользователей с ролью контроллера.')
            ->striped();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function records(): array
    {
        $organization = Filament::getTenant();
        $user = auth()->user();

        if (! $organization instanceof Organization || ! $user instanceof User) {
            return [];
        }

        $billingPeriod = DashboardBillingPeriod::resolve(
            $organization,
            $this->pageFilters['billing_period_id'] ?? null,
        );

        if (! $billingPeriod instanceof BillingPeriod) {
            return [];
        }

        $progress = app(DashboardMetrics::class)
            ->controllerProgress($organization, $billingPeriod, $user);

        usort($progress, fn (array $first, array $second): int => $first['percent'] <=> $second['percent']);

        $records = [];

        foreach (array_slice($progress, 0, self::ROW_LIMIT) as $row) {
            $records[$row['controller_id']] = [
                ...$row,
                'percent_label' => $this->formatPercent((float) $row['percent']),
                'percent_color' => $this->percentColor((float) $row['percent']),
            ];
        }

        return $records;
    }

    private function formatPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').' %';
    }

    private function percentColor(float $percent): string
    {
        return match (true) {
            $percent < 70.0 => 'danger',
            $percent < 95.0 => 'warning',
            default => 'success',
        };
    }
}
