<?php

namespace App\Reports\Builder;

/**
 * One value of a built report summary.
 *
 * The expression is a SQL aggregate over the summary rows (`report_rows`). The
 * same expression computes both a group and the «Итого» row, so a sum adds up
 * across the groups, while an average or a percentage is recomputed from the
 * totals of its own row instead of being summed or averaged over the groups.
 */
final readonly class ReportMetric
{
    public const string CLIENTS = 'clients';

    public const string COUNT = 'count';

    public const string SUM = 'sum';

    public const string AVERAGE = 'average';

    public function __construct(
        public string $key,
        public string $label,
        public ReportFieldType $type,
        public string $expression,
    ) {}

    /**
     * Distinct clients of the group.
     */
    public static function clients(): self
    {
        return new self(self::CLIENTS, 'Абонентов', ReportFieldType::Int, 'count(distinct report_rows.client_id)');
    }

    public static function count(string $label): self
    {
        return new self(self::COUNT, $label, ReportFieldType::Int, 'count(*)');
    }

    /**
     * @param  string  $column  Summary row column holding the summed amount.
     */
    public static function sum(string $label, string $column): self
    {
        return new self(self::SUM, $label, ReportFieldType::Money, "coalesce(sum(report_rows.{$column}), 0)");
    }

    /**
     * The sum of the group divided by its number of rows.
     *
     * @param  string  $column  Summary row column holding the summed amount.
     */
    public static function average(string $label, string $column): self
    {
        return new self(self::AVERAGE, $label, ReportFieldType::Money, "sum(report_rows.{$column}) / nullif(count(*), 0)");
    }

    /**
     * One sum of the group as a percentage of another, `null` when the base is zero.
     *
     * @param  string  $column  Summary row column of the part.
     * @param  string  $baseColumn  Summary row column of the whole.
     */
    public static function percentage(string $key, string $label, string $column, string $baseColumn): self
    {
        return new self(
            $key,
            $label,
            ReportFieldType::Percent,
            "sum(report_rows.{$column}) / nullif(sum(report_rows.{$baseColumn}), 0) * 100",
        );
    }
}
