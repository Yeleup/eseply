<?php

namespace App\Reports\Builder;

/**
 * What a built report summary is grouped by.
 *
 * Cities, regions, streets and controllers are shared by every source and are
 * resolved from the client address of the row. A source may add its own
 * dimension, which groups by a value of the row, such as the payment method.
 */
final readonly class ReportDimension
{
    public const string CITY = 'city';

    public const string REGION = 'region';

    public const string STREET = 'street';

    public const string CONTROLLER = 'controller';

    /**
     * @param  string|null  $column  Summary row column the source dimension groups by, `null` for the address dimensions.
     * @param  array<string, string>  $labels  Labels of the column values, in the order the groups are shown.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $heading,
        public ?string $column = null,
        public array $labels = [],
        public string $emptyLabel = 'Не указано',
    ) {}

    public static function city(): self
    {
        return new self(self::CITY, 'По городам', 'Город', emptyLabel: 'Без города');
    }

    public static function region(): self
    {
        return new self(self::REGION, 'По районам', 'Район', emptyLabel: 'Без района');
    }

    public static function street(): self
    {
        return new self(self::STREET, 'По улицам', 'Улица', emptyLabel: 'Без улицы');
    }

    public static function controller(): self
    {
        return new self(self::CONTROLLER, 'По контроллерам', 'Контроллер', emptyLabel: 'Без имени');
    }

    /**
     * A dimension of the source that groups by one column of the summary rows.
     *
     * @param  array<string, string>  $labels
     */
    public static function column(string $key, string $label, string $heading, string $column, array $labels): self
    {
        return new self($key, $label, $heading, $column, $labels);
    }

    /**
     * Label of a value of the source column.
     */
    public function labelFor(mixed $value): string
    {
        if ($value === null || $value === '') {
            return $this->emptyLabel;
        }

        return $this->labels[(string) $value] ?? (string) $value;
    }
}
