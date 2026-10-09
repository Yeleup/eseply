<?php

namespace App\Reports\Builder;

/**
 * Data sources of the report builder, in the order the page offers them.
 *
 * The first source is the default one: an unknown source key from the address
 * falls back to it.
 */
class ReportSourceRegistry
{
    /**
     * @var list<class-string<ReportSource>>
     */
    private const SOURCES = [
        PaymentsSource::class,
        AccrualsSource::class,
    ];

    /**
     * @return list<ReportSource>
     */
    public function all(): array
    {
        return array_map(
            fn (string $source): ReportSource => app($source),
            self::SOURCES,
        );
    }

    public function find(mixed $key): ?ReportSource
    {
        if (! is_string($key)) {
            return null;
        }

        foreach ($this->all() as $source) {
            if ($source->key() === $key) {
                return $source;
            }
        }

        return null;
    }

    public function default(): ReportSource
    {
        return $this->all()[0];
    }
}
