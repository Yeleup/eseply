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
use App\Support\ReceiptPrintSelection;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ReceiptPrintController extends Controller
{
    private const PRINT_FILTER_KEYS = [
        'billing_period_id',
        'region_id',
        'street_id',
        'controller_id',
    ];

    /**
     * Массовая печать раскладывает экземпляры сеткой 2×4 на листе A4.
     * Число чётное, поэтому оба экземпляра одной квитанции всегда
     * попадают на один лист.
     */
    public const BULK_COPIES_PER_A4_PAGE = 8;

    /**
     * Квитанций в одной порции потоковой массовой печати. Кратно
     * BULK_COPIES_PER_A4_PAGE, поэтому порция занимает целое число листов.
     */
    public const BULK_PRINT_CHUNK_SIZE = 200;

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
    ): Response|StreamedResponse {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        abort_unless(
            $tenant instanceof Organization
                && $user instanceof User
                && (string) $tenant->getRouteKey() === $tenantKey
                && $user->canManageOrganization($tenant),
            404,
        );

        $receiptIds = $this->selectedReceiptIds($request, $user, $tenant);
        $filters = $this->printFilters($request);

        abort_unless($receiptIds !== null || $this->hasPrintFilters($filters), 404);

        $printLimit = ReceiptPrintSelection::limit();
        $receiptsQuery = Receipt::query()->whereBelongsTo($tenant);

        if ($receiptIds !== null) {
            $receiptsCount = $receiptIds->count();

            if ($receiptsCount <= $printLimit) {
                $receiptsQuery->whereKey($receiptIds);
                $periodLabel = $this->selectedPeriodLabel(clone $receiptsQuery);
            } else {
                $periodLabel = 'Выбранные квитанции';
            }
        } else {
            $periodLabel = $this->applyPrintFilters($receiptsQuery, $tenant, $filters);
            $receiptsCount = (clone $receiptsQuery)->count();
        }

        if ($receiptsCount > $printLimit) {
            return $this->bulkPrintStateResponse($tenant, $buildReceiptPrintViewData, [
                'periodLabel' => $periodLabel,
                'receiptsCount' => $receiptsCount,
                'printLimit' => $printLimit,
                'limitExceeded' => true,
            ], 422);
        }

        $orderedReceiptIds = $this->orderByPrintSequence($receiptsQuery)
            ->pluck($receiptsQuery->qualifyColumn('id'));

        abort_if($receiptIds !== null && $orderedReceiptIds->count() !== $receiptIds->count(), 404);

        if ($orderedReceiptIds->isEmpty()) {
            return $this->bulkPrintStateResponse($tenant, $buildReceiptPrintViewData, [
                'periodLabel' => $periodLabel,
                'receiptsCount' => 0,
                'printLimit' => $printLimit,
                'limitExceeded' => false,
            ]);
        }

        return $this->streamBulkPrint($tenant, $orderedReceiptIds, $periodLabel, $printLimit, $buildReceiptPrintViewData);
    }

    /**
     * Отдаёт массовую печать потоком: квитанции загружаются и отрисовываются
     * порциями по BULK_PRINT_CHUNK_SIZE, поэтому в памяти одновременно
     * только одна порция, а число запросов растёт с числом порций,
     * а не квитанций. Порция кратна BULK_COPIES_PER_A4_PAGE, поэтому листы
     * A4 заполняются так же, как при отрисовке всей выборки разом.
     *
     * Всё, что можно проверить заранее (доступ, предел, состав выборки,
     * шаблон), проверяется до начала ответа. Если порция не загрузилась
     * или не отрисовалась уже после отправки начала страницы, ошибка
     * записывается в лог, а страница закрывается состоянием ошибки: листы
     * и кнопка печати скрываются, автоматическая печать не запускается.
     *
     * @param  Collection<int, int>  $orderedReceiptIds
     */
    private function streamBulkPrint(
        Organization $tenant,
        Collection $orderedReceiptIds,
        string $periodLabel,
        int $printLimit,
        BuildReceiptPrintViewData $buildReceiptPrintViewData,
    ): StreamedResponse {
        $tenant->loadMissing(['utilityService', 'receiptTemplate']);
        $template = $buildReceiptPrintViewData->template($tenant);
        $receiptsCount = $orderedReceiptIds->count();
        $viewData = [
            'periodLabel' => $periodLabel,
            'receiptsCount' => $receiptsCount,
            'printPagesCount' => (int) ceil($receiptsCount * $template['copiesPerPage'] / self::BULK_COPIES_PER_A4_PAGE),
            'templateCss' => $template['css'],
            'printLimit' => $printLimit,
            'limitExceeded' => false,
            'printFailed' => false,
        ];
        $startHtml = view('receipts.bulk-print.start', $viewData)->render();

        return response()->stream(function () use ($tenant, $orderedReceiptIds, $viewData, $startHtml, $buildReceiptPrintViewData): void {
            echo $startHtml;

            try {
                foreach ($orderedReceiptIds->chunk(self::BULK_PRINT_CHUNK_SIZE) as $chunkReceiptIds) {
                    $receipts = $this->loadPrintChunk($tenant, $chunkReceiptIds);

                    foreach ($this->printPages($buildReceiptPrintViewData->handleMany($receipts)) as $pageCopies) {
                        echo view('receipts.bulk-print.page', ['pageCopies' => $pageCopies])->render();
                    }

                    $this->flushOutput();
                }
            } catch (Throwable $exception) {
                report($exception);

                $viewData['printFailed'] = true;
            }

            echo view('receipts.bulk-print.end', $viewData)->render();
        }, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Порция квитанций для потоковой печати. Запрос снова ограничен
     * организацией: квитанция, удалённая или перенесённая в другую
     * организацию после отбора выборки, не печатается, а прерывает печать.
     *
     * @param  Collection<int, int>  $chunkReceiptIds
     * @return EloquentCollection<int, Receipt>
     */
    private function loadPrintChunk(Organization $tenant, Collection $chunkReceiptIds): EloquentCollection
    {
        $receipts = $this->orderByPrintSequence(
            Receipt::query()
                ->whereBelongsTo($tenant)
                ->whereKey($chunkReceiptIds->all())
                ->with([
                    'billingPeriod',
                    'client.region',
                    'client.street',
                ]),
        )->get();

        if ($receipts->count() !== $chunkReceiptIds->count()) {
            throw new RuntimeException('Состав квитанций массовой печати изменился во время печати.');
        }

        return $receipts->each(fn (Receipt $receipt): Receipt => $receipt->setRelation('organization', $tenant));
    }

    /**
     * Порядок печати: лицевой счёт, номер квитанции и id как последний ключ,
     * чтобы порядок был однозначным и совпадал между отбором выборки
     * и загрузкой порций.
     *
     * @param  Builder<Receipt>  $receiptsQuery
     * @return Builder<Receipt>
     */
    private function orderByPrintSequence(Builder $receiptsQuery): Builder
    {
        return $receiptsQuery
            ->orderBy('account_number')
            ->orderBy('receipt_number')
            ->orderBy($receiptsQuery->qualifyColumn('id'));
    }

    /**
     * Страница массовой печати без листов: пустая выборка или превышен предел.
     *
     * @param  array{periodLabel: string, receiptsCount: int, printLimit: int, limitExceeded: bool}  $viewData
     */
    private function bulkPrintStateResponse(
        Organization $tenant,
        BuildReceiptPrintViewData $buildReceiptPrintViewData,
        array $viewData,
        int $status = 200,
    ): Response {
        $tenant->loadMissing('receiptTemplate');

        $viewData += [
            'printPagesCount' => 0,
            'printFailed' => false,
            'templateCss' => $buildReceiptPrintViewData->template($tenant)['css'],
        ];

        return response(
            view('receipts.bulk-print.start', $viewData)->render().view('receipts.bulk-print.end', $viewData)->render(),
            $status,
        )
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    private function flushOutput(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    /**
     * @param  list<array{renderedCopies: array<string, string>}>  $receiptPrintData
     * @return list<list<array{copyTitle: string, renderedCopy: string}>>
     */
    private function printPages(array $receiptPrintData): array
    {
        return collect($receiptPrintData)
            ->flatMap(fn (array $printData): array => collect($printData['renderedCopies'])
                ->map(fn (string $renderedCopy, string $copyTitle): array => [
                    'copyTitle' => $copyTitle,
                    'renderedCopy' => $renderedCopy,
                ])
                ->values()
                ->all())
            ->chunk(self::BULK_COPIES_PER_A4_PAGE)
            ->map(fn (Collection $pageCopies): array => $pageCopies->values()->all())
            ->values()
            ->all();
    }

    /**
     * Фильтр, переданный массивом (`region_id[]=…`), даёт 404, а не
     * приводится к числу.
     *
     * @return array<string, int>
     */
    private function printFilters(Request $request): array
    {
        $filters = [];

        foreach ([...self::PRINT_FILTER_KEYS, 'amount_due_positive'] as $filterKey) {
            abort_if(is_array($request->query($filterKey)), 404);

            $filters[$filterKey] = $request->integer($filterKey);
        }

        return $filters;
    }

    /**
     * @param  array<string, int>  $filters
     */
    private function hasPrintFilters(array $filters): bool
    {
        foreach (self::PRINT_FILTER_KEYS as $filterKey) {
            if ($filters[$filterKey] > 0) {
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

        if ($filters['amount_due_positive'] > 0) {
            $receiptsQuery->where('closing_balance', '>', 0);
        }

        return $periodLabel;
    }

    /**
     * Выбранные квитанции передаются токеном `selection`, который выдаёт
     * bulk-действие «Печатать выбранные». Чужой, просроченный,
     * неизвестный или не строковый (например, `selection[]=…`) токен даёт 404.
     *
     * @return Collection<int, int>|null
     */
    private function selectedReceiptIds(Request $request, User $user, Organization $tenant): ?Collection
    {
        if (! $request->has('selection')) {
            return null;
        }

        $selectionToken = $request->query('selection');

        abort_unless(is_string($selectionToken) && $selectionToken !== '', 404);

        $receiptIds = ReceiptPrintSelection::resolve($selectionToken, $user, $tenant);

        abort_unless($receiptIds?->isNotEmpty(), 404);

        return $receiptIds;
    }

    /**
     * @param  Builder<Receipt>  $selectedReceiptsQuery
     */
    private function selectedPeriodLabel(Builder $selectedReceiptsQuery): string
    {
        $periodLabels = $selectedReceiptsQuery
            ->select('billing_period_id')
            ->distinct()
            ->with('billingPeriod')
            ->get()
            ->map(fn (Receipt $receipt): ?string => $receipt->billingPeriod?->label ?? $receipt->period)
            ->filter()
            ->unique()
            ->values();

        return $periodLabels->count() === 1 ? (string) $periodLabels->first() : 'Выбранные квитанции';
    }
}
