<?php

namespace App\Filament\Widgets;

use App\Filament\Support\OrganizationMemberAccess;
use App\Kaspi\KaspiActivity;
use App\Models\Organization;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Tiles of the Kaspi journal. «Оплат сегодня» counts by the Kaspi date, as
 * Kaspi's register does; «Ошибок за сутки» by the time a request came in.
 */
class KaspiOperationsStatsWidget extends StatsOverviewWidget
{
    /**
     * The tiles refresh with the page, not on their own.
     */
    protected ?string $pollingInterval = null;

    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }

    /**
     * @return int|array<string, ?int>
     */
    protected function getColumns(): int|array
    {
        return 3;
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $organization = Filament::getTenant();

        if (! $organization instanceof Organization || ! OrganizationMemberAccess::canManageTenant()) {
            return [];
        }

        $today = KaspiActivity::postedToday($organization);
        $pending = KaspiActivity::pendingCount($organization);
        $errors = KaspiActivity::errorsInLastDay($organization);
        $lastRequestAt = KaspiActivity::lastRequestAt($organization);

        return [
            Stat::make('Оплат сегодня', (string) $today['count'])
                ->description('на '.number_format($today['sum'], 2, ',', ' ').' ₸')
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color('success'),
            Stat::make('В обработке', (string) $pending)
                ->description($pending > 0 ? 'проведётся само после закрытия месяца' : 'всё проведено')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color($pending > 0 ? 'warning' : 'gray'),
            Stat::make('Ошибок за сутки', (string) $errors)
                ->description($lastRequestAt === null
                    ? 'запросов от Kaspi ещё не было'
                    : 'последний запрос '.KaspiActivity::formatMoment($lastRequestAt))
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($errors > 0 ? 'danger' : 'gray'),
        ];
    }
}
