<?php

namespace App\Filament\Support;

/**
 * Livewire filter state is user-controllable raw input, so identifiers coming from
 * a filter are normalized before they reach a query and anything else is dropped.
 */
final class FilterIdentifiers
{
    public static function one(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            return ((int) $value) > 0 ? (int) $value : null;
        }

        return null;
    }

    /**
     * @return list<int>
     */
    public static function many(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $identifiers = [];

        foreach ($value as $item) {
            $identifier = self::one($item);

            if ($identifier !== null) {
                $identifiers[] = $identifier;
            }
        }

        return array_values(array_unique($identifiers));
    }
}
