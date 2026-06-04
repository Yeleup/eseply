<?php

namespace App\Reports;

use App\Reports\Contracts\OrganizationReport;

class ReportRegistry
{
    /**
     * @var list<class-string<OrganizationReport>>
     */
    private const REPORTS = [
        MeterReadingSheetReport::class,
    ];

    /**
     * @return list<OrganizationReport>
     */
    public function all(): array
    {
        return array_map(
            fn (string $report): OrganizationReport => app($report),
            self::REPORTS,
        );
    }

    public function find(string $slug): ?OrganizationReport
    {
        foreach ($this->all() as $report) {
            if ($report->slug() === $slug) {
                return $report;
            }
        }

        return null;
    }
}
