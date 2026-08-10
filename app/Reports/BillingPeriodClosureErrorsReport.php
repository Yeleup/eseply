<?php

namespace App\Reports;

use App\Models\BillingPeriod;
use App\Models\BillingPeriodClosureError;
use App\Reports\Concerns\FormatsReportValues;
use App\Support\BillingClosureIssue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillingPeriodClosureErrorsReport
{
    use FormatsReportValues;

    /**
     * Number of closure error rows read per query while streaming the export.
     */
    public const int EXPORT_CHUNK_SIZE = 500;

    public function billingTypeName(?string $billingType): string
    {
        return $this->billingTypeLabel($billingType);
    }

    /**
     * @return array<string, string>
     */
    public function billingTypeOptions(): array
    {
        return collect(['meter', 'per_person', 'fixed'])
            ->mapWithKeys(fn (string $billingType): array => [
                $billingType => $this->billingTypeLabel($billingType),
            ])
            ->all();
    }

    /**
     * @return Builder<BillingPeriodClosureError>
     */
    public function query(BillingPeriod $billingPeriod): Builder
    {
        return BillingPeriodClosureError::query()
            ->where('billing_period_id', $billingPeriod->getKey())
            ->where('organization_id', $billingPeriod->organization_id)
            ->orderBy('account_number')
            ->orderBy('id');
    }

    /**
     * Number of errors per stable error code, so an operator sees what has to be
     * fixed without reading every row of the report.
     *
     * @return Collection<int, array{code: string, label: string, total: int}>
     */
    public function codeSummary(BillingPeriod $billingPeriod): Collection
    {
        return BillingPeriodClosureError::query()
            ->select('code')
            ->selectRaw('COUNT(*) as total')
            ->where('billing_period_id', $billingPeriod->getKey())
            ->where('organization_id', $billingPeriod->organization_id)
            ->groupBy('code')
            ->orderByDesc('total')
            ->orderBy('code')
            ->get()
            ->map(fn (BillingPeriodClosureError $error): array => [
                'code' => (string) $error->code,
                'label' => BillingClosureIssue::labelFor($error->code),
                'total' => (int) $error->getAttribute('total'),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public function codeOptions(BillingPeriod $billingPeriod): array
    {
        return $this->codeSummary($billingPeriod)
            ->mapWithKeys(fn (array $summary): array => [$summary['code'] => $summary['label']])
            ->all();
    }

    public function downloadExcel(BillingPeriod $billingPeriod): StreamedResponse
    {
        return $this->downloadXlsx(
            $this->excelFileName($billingPeriod),
            $this->excelOptions(),
            [
                'Лицевой счёт',
                'ФИО',
                'Тип начисления',
                'Код ошибки',
                'Причина',
                'Контекст',
            ],
            fn (): iterable => $this->query($billingPeriod)->lazy(self::EXPORT_CHUNK_SIZE),
            fn (BillingPeriodClosureError $error): array => $this->excelCells($error),
        );
    }

    public function formatContext(BillingPeriodClosureError $error): ?string
    {
        $context = $error->context;

        if (! is_array($context) || $context === []) {
            return null;
        }

        return collect($context)
            ->map(fn (mixed $value, string $key): string => $key.': '.$this->formatContextValue($value))
            ->implode('; ');
    }

    private function formatContextValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return '-';
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private function excelFileName(BillingPeriod $billingPeriod): string
    {
        return sprintf(
            'billing-period-closure-errors-%d-%s-%s.xlsx',
            $billingPeriod->organization_id,
            $billingPeriod->code,
            today()->format('Y-m-d'),
        );
    }

    private function excelOptions(): Options
    {
        $options = new Options;
        $options->setColumnWidth(16, 1);
        $options->setColumnWidth(30, 2);
        $options->setColumnWidth(22, 3);
        $options->setColumnWidth(30, 4);
        $options->setColumnWidth(46, 5);
        $options->setColumnWidth(36, 6);

        return $options;
    }

    /**
     * @return list<Cell>
     */
    private function excelCells(BillingPeriodClosureError $error): array
    {
        return [
            new StringCell((string) ($error->account_number ?? ''), null),
            new StringCell((string) ($error->client_name ?? ''), null),
            new StringCell($this->billingTypeLabel($error->billing_type), null),
            new StringCell((string) $error->code, null),
            new StringCell((string) $error->message, (new Style)->setShouldWrapText()),
            new StringCell($this->formatContext($error) ?? '', (new Style)->setShouldWrapText()),
        ];
    }
}
