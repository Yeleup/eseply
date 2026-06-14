<?php

namespace App\Http\Controllers;

use App\Actions\BuildReceiptPrintViewData;
use App\Models\BillingPeriod;
use App\Models\Organization;
use App\Models\Receipt;
use App\Models\Region;
use App\Models\Street;
use App\Models\User;
use App\OrganizationMemberRole;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class ReceiptPrintController extends Controller
{
    private const PRINT_FILTER_KEYS = [
        'billing_period_id',
        'region_id',
        'street_id',
        'controller_id',
    ];

    public function single(string $tenantKey, Receipt $receipt, BuildReceiptPrintViewData $buildReceiptPrintViewData): Response
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        abort_unless(
            $tenant instanceof Organization
                && $user instanceof User
                && (string) $tenant->getRouteKey() === $tenantKey
                && (int) $receipt->organization_id === (int) $tenant->getKey()
                && $user->canManageOrganization($tenant),
            404,
        );

        return response()
            ->view('receipts.print', $buildReceiptPrintViewData->handle($receipt), 200)
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function bulk(
        string $tenantKey,
        Request $request,
        BuildReceiptPrintViewData $buildReceiptPrintViewData,
    ): Response {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        abort_unless(
            $tenant instanceof Organization
                && $user instanceof User
                && (string) $tenant->getRouteKey() === $tenantKey
                && $user->canManageOrganization($tenant),
            404,
        );

        $receiptIds = $this->receiptIds($request);
        $filters = $this->printFilters($request);

        abort_unless($receiptIds->isNotEmpty() || $this->hasPrintFilters($filters), 404);

        $receiptsQuery = Receipt::query()
            ->whereBelongsTo($tenant)
            ->with([
                'billingPeriod',
                'client.region',
                'client.street',
                'organization.utilityService',
            ])
            ->orderBy('account_number')
            ->orderBy('receipt_number');

        if ($receiptIds->isNotEmpty()) {
            $receipts = $receiptsQuery
                ->whereKey($receiptIds)
                ->get();

            abort_unless($receipts->count() === $receiptIds->count(), 404);

            $periodLabel = $this->selectedPeriodLabel($receipts);
        } else {
            $periodLabel = $this->applyPrintFilters($receiptsQuery, $tenant, $filters);

            $receipts = $receiptsQuery
                ->get();
        }

        return response()
            ->view('receipts.bulk-print', [
                'periodLabel' => $periodLabel,
                'receiptPrintData' => $receipts
                    ->map(fn (Receipt $receipt): array => $buildReceiptPrintViewData->handle($receipt))
                    ->all(),
            ], 200)
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /**
     * @return array<string, int>
     */
    private function printFilters(Request $request): array
    {
        $filters = [];

        foreach (self::PRINT_FILTER_KEYS as $filterKey) {
            $filters[$filterKey] = $request->integer($filterKey);
        }

        return $filters;
    }

    /**
     * @param  array<string, int>  $filters
     */
    private function hasPrintFilters(array $filters): bool
    {
        foreach ($filters as $value) {
            if ($value > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Builder<Receipt>  $receiptsQuery
     * @param  array<string, int>  $filters
     */
    private function applyPrintFilters(Builder $receiptsQuery, Organization $tenant, array $filters): string
    {
        $periodLabel = 'Выбранные фильтры';

        if ($filters['billing_period_id'] > 0) {
            $billingPeriod = BillingPeriod::query()
                ->whereBelongsTo($tenant)
                ->whereKey($filters['billing_period_id'])
                ->firstOrFail();

            $receiptsQuery->whereBelongsTo($billingPeriod);
            $periodLabel = $billingPeriod->label;
        }

        if ($filters['region_id'] > 0) {
            Region::query()
                ->whereBelongsTo($tenant)
                ->whereKey($filters['region_id'])
                ->firstOrFail();

            $receiptsQuery->whereHas(
                'client',
                fn (Builder $query): Builder => $query->where($query->qualifyColumn('region_id'), $filters['region_id']),
            );
        }

        if ($filters['street_id'] > 0) {
            Street::query()
                ->whereBelongsTo($tenant)
                ->whereKey($filters['street_id'])
                ->firstOrFail();

            $receiptsQuery->whereHas(
                'client',
                fn (Builder $query): Builder => $query->where($query->qualifyColumn('street_id'), $filters['street_id']),
            );
        }

        if ($filters['controller_id'] > 0) {
            $controller = $tenant->users()
                ->wherePivot('role', OrganizationMemberRole::Controller->value)
                ->whereKey($filters['controller_id'])
                ->firstOrFail();

            $receiptsQuery->whereHas(
                'client',
                fn (Builder $query): Builder => $query->visibleToOrganizationMember($controller, $tenant),
            );
        }

        return $periodLabel;
    }

    /**
     * @return Collection<int, int>
     */
    private function receiptIds(Request $request): Collection
    {
        return collect(Arr::wrap($request->input('receipt_ids', [])))
            ->filter(fn (mixed $receiptId): bool => is_numeric($receiptId))
            ->map(fn (mixed $receiptId): int => (int) $receiptId)
            ->filter(fn (int $receiptId): bool => $receiptId > 0)
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, Receipt>  $receipts
     */
    private function selectedPeriodLabel(Collection $receipts): string
    {
        $periodLabels = $receipts
            ->map(fn (Receipt $receipt): ?string => $receipt->billingPeriod?->label ?? $receipt->period)
            ->filter()
            ->unique()
            ->values();

        return $periodLabels->count() === 1 ? (string) $periodLabels->first() : 'Выбранные квитанции';
    }
}
