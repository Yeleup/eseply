<?php

namespace App\Reports\Builder;

use App\Models\BillingPeriod;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * One column the operator may put into a built report.
 *
 * A field is either read by SQL — a column or an expression of the source query,
 * selected under its own alias so it sorts and exports like any column — or
 * resolved in PHP from relations the query loads in advance, such as the address.
 *
 * A computed field is a SQL formula over other values of the row. The formula
 * is fixed in code: the operator only chooses whether to show it.
 */
final readonly class ReportField
{
    /**
     * @param  string|null  $expression  Column or SQL expression of the source query, or `null` for a field resolved in PHP.
     * @param  list<string>  $relations  Relations the resolver reads, loaded once per page instead of once per row.
     * @param  (Closure(Model, ?BillingPeriod): mixed)|null  $resolver
     * @param  (Closure(mixed): string)|null  $formatUsing  Label of a stored text value, such as the payment method.
     */
    public function __construct(
        public string $key,
        public string $label,
        public ReportFieldType $type,
        public ?string $expression = null,
        public bool $computed = false,
        public bool $sortable = false,
        public bool $searchable = false,
        public array $relations = [],
        public ?Closure $resolver = null,
        public ?Closure $formatUsing = null,
    ) {}

    /**
     * A column of the source query.
     *
     * @param  (Closure(mixed): string)|null  $formatUsing
     */
    public static function column(
        string $key,
        string $label,
        ReportFieldType $type,
        string $expression,
        bool $searchable = false,
        ?Closure $formatUsing = null,
    ): self {
        return new self(
            key: $key,
            label: $label,
            type: $type,
            expression: $expression,
            sortable: true,
            searchable: $searchable,
            formatUsing: $formatUsing,
        );
    }

    /**
     * A formula over other values of the row, computed by the database.
     *
     * The formula returns `null` when its denominator is zero, and the report shows a dash.
     */
    public static function computed(string $key, string $label, ReportFieldType $type, string $formula): self
    {
        return new self(
            key: $key,
            label: $label,
            type: $type,
            expression: $formula,
            computed: true,
            sortable: true,
        );
    }

    /**
     * A text value built in PHP from the row and the relations it loads in advance.
     *
     * @param  Closure(Model, ?BillingPeriod): mixed  $resolver
     * @param  list<string>  $relations
     */
    public static function resolved(string $key, string $label, Closure $resolver, array $relations = []): self
    {
        return new self(
            key: $key,
            label: $label,
            type: ReportFieldType::Text,
            relations: $relations,
            resolver: $resolver,
        );
    }

    /**
     * Alias the expression is selected under. It never matches a model attribute,
     * so the raw database value is not cast by the model.
     */
    public function alias(): string
    {
        return 'field_'.$this->key;
    }

    /**
     * Value of the field in the row: a number for the numeric types, `null` when the
     * row has no value or the formula divides by zero.
     */
    public function valueOf(Model $record, ?BillingPeriod $billingPeriod): mixed
    {
        if ($this->resolver instanceof Closure) {
            return ($this->resolver)($record, $billingPeriod);
        }

        $value = $record->getAttribute($this->alias());

        if ($value === null) {
            return null;
        }

        return match ($this->type) {
            ReportFieldType::Int => (int) $value,
            ReportFieldType::Money, ReportFieldType::Percent, ReportFieldType::Float => (float) $value,
            default => $this->formatUsing instanceof Closure ? ($this->formatUsing)($value) : $value,
        };
    }
}
