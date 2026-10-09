<?php

namespace App\Reports\Builder;

use App\Filament\Support\FilterIdentifiers;
use App\Models\BillingPeriod;
use App\Models\Organization;

/**
 * One assembled report: the source, its chosen fields, dimension and metrics,
 * the billing period and the mode.
 *
 * The build lives only in the address of the page, so every part of it arrives as
 * raw user input. The parsing methods accept only keys of the source catalog; an
 * unknown value is ignored and the default of the source takes its place.
 */
final readonly class ReportBuild
{
    public const string MODE_DETAIL = 'detail';

    public const string MODE_SUMMARY = 'summary';

    /**
     * Dimension key of «Без группировки».
     */
    public const string NO_DIMENSION = 'none';

    /**
     * @param  list<ReportField>  $fields  Chosen fields in catalog order.
     * @param  list<ReportMetric>  $metrics  Enabled metrics in catalog order.
     */
    public function __construct(
        public ReportSource $source,
        public array $fields,
        public ?ReportDimension $dimension,
        public array $metrics,
        public ?BillingPeriod $billingPeriod,
        public string $mode,
    ) {}

    /**
     * A summary needs a dimension. Without one the detail table is shown.
     */
    public function isSummary(): bool
    {
        return $this->mode === self::MODE_SUMMARY && $this->dimension instanceof ReportDimension;
    }

    /**
     * Known field keys of the input in catalog order, whatever order they were clicked in.
     * Without a single known key the source defaults are used.
     *
     * @return list<ReportField>
     */
    public static function fieldsOf(ReportSource $source, mixed $input): array
    {
        $keys = self::keyList($input);

        $fields = array_values(array_filter(
            $source->fields(),
            fn (ReportField $field): bool => in_array($field->key, $keys, true),
        ));

        if ($fields !== []) {
            return $fields;
        }

        return array_values(array_filter(
            $source->fields(),
            fn (ReportField $field): bool => in_array($field->key, $source->defaultFieldKeys(), true),
        ));
    }

    /**
     * The value kept in the address: empty while the fields are the source defaults.
     *
     * @param  list<ReportField>  $fields
     */
    public static function fieldsInput(ReportSource $source, array $fields): string
    {
        $keys = array_map(fn (ReportField $field): string => $field->key, $fields);
        $defaults = array_map(
            fn (ReportField $field): string => $field->key,
            self::fieldsOf($source, null),
        );

        return $keys === $defaults ? '' : implode(',', $keys);
    }

    /**
     * `null` means «Без группировки». An unknown key falls back to the source default.
     */
    public static function dimensionOf(ReportSource $source, mixed $input): ?ReportDimension
    {
        if ($input === self::NO_DIMENSION) {
            return null;
        }

        $dimensions = $source->dimensions();

        foreach ($dimensions as $dimension) {
            if ($dimension->key === $input) {
                return $dimension;
            }
        }

        foreach ($dimensions as $dimension) {
            if ($dimension->key === $source->defaultDimensionKey()) {
                return $dimension;
            }
        }

        return null;
    }

    public static function dimensionInput(ReportSource $source, ?ReportDimension $dimension): string
    {
        if (! $dimension instanceof ReportDimension) {
            return self::NO_DIMENSION;
        }

        return $dimension->key === $source->defaultDimensionKey() ? '' : $dimension->key;
    }

    /**
     * Metrics of the source except the ones the input turns off.
     *
     * @return list<ReportMetric>
     */
    public static function metricsOf(ReportSource $source, mixed $disabledInput): array
    {
        $disabled = self::keyList($disabledInput);

        return array_values(array_filter(
            $source->metrics(),
            fn (ReportMetric $metric): bool => ! in_array($metric->key, $disabled, true),
        ));
    }

    /**
     * The value kept in the address: the keys of the metrics that are turned off.
     *
     * @param  list<ReportMetric>  $metrics  Enabled metrics.
     */
    public static function disabledMetricsInput(ReportSource $source, array $metrics): string
    {
        $enabled = array_map(fn (ReportMetric $metric): string => $metric->key, $metrics);

        $disabled = array_filter(
            $source->metrics(),
            fn (ReportMetric $metric): bool => ! in_array($metric->key, $enabled, true),
        );

        return implode(',', array_map(fn (ReportMetric $metric): string => $metric->key, $disabled));
    }

    public static function modeOf(mixed $input): string
    {
        return $input === self::MODE_SUMMARY ? self::MODE_SUMMARY : self::MODE_DETAIL;
    }

    /**
     * Only a positive integer written with digits is kept: a fraction, an exponent,
     * a sign or spaces make the value unknown, and the default billing period is used.
     * Whether the billing period belongs to the organization is checked while it is resolved.
     */
    public static function billingPeriodIdOf(mixed $input): ?int
    {
        return FilterIdentifiers::one($input);
    }

    /**
     * The chosen billing period of the organization, otherwise the default of the source.
     */
    public static function billingPeriodOf(ReportSource $source, Organization $organization, ?int $billingPeriodId): ?BillingPeriod
    {
        if ($billingPeriodId !== null) {
            $billingPeriod = BillingPeriod::query()
                ->forOrganization($organization)
                ->whereKey($billingPeriodId)
                ->first();

            if ($billingPeriod instanceof BillingPeriod) {
                return $billingPeriod;
            }
        }

        return $source->defaultBillingPeriodFor($organization);
    }

    /**
     * @return list<string>
     */
    private static function keyList(mixed $input): array
    {
        if (! is_string($input) || $input === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $input)),
            fn (string $key): bool => $key !== '',
        ));
    }
}
