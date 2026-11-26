<?php

namespace App\Support;

use App\Models\Client;

/**
 * The address of a subscriber as the pages, the reports, the receipts and
 * Kaspi write it: «ул. Абая, д. 12, кв. 5» — the street, `д. <house>`,
 * `кв. <apartment>`, joined with «, », empty parts skipped.
 *
 * An empty address is an empty string: what to show instead — «Адрес не
 * указан», «-», the region or nothing at all — each place decides itself.
 *
 * Which name counts as empty differs, and each place keeps its own rule: the
 * cash desk (and Kaspi, which repeats it) skips what PHP takes for false —
 * a street named «0» too, a name of blanks not; the reading entry, the
 * reports and the receipts skip what `filled()` takes for blank — a name of
 * blanks too, «0» not.
 */
final class ClientAddress
{
    /**
     * As the «Приём оплат» page shows it and Kaspi sends it: the street, the
     * house and the apartment, a part PHP takes for false skipped.
     */
    public static function forCashDesk(Client $client): string
    {
        return implode(', ', array_filter(self::streetHouseApartment($client)));
    }

    /**
     * The street, the house and the apartment, a blank part skipped, as the
     * reading entry shows them.
     */
    public static function of(Client $client): string
    {
        return self::joinFilled(self::streetHouseApartment($client));
    }

    /**
     * The same with the region in front, as the reports and the receipts print it.
     */
    public static function withRegion(Client $client): string
    {
        return self::joinFilled([
            $client->region?->name,
            ...self::streetHouseApartment($client),
        ]);
    }

    /**
     * @return list<string|null>
     */
    private static function streetHouseApartment(Client $client): array
    {
        return [
            $client->street?->name,
            filled($client->house) ? 'д. '.$client->house : null,
            filled($client->apartment) ? 'кв. '.$client->apartment : null,
        ];
    }

    /**
     * @param  list<string|null>  $parts
     */
    private static function joinFilled(array $parts): string
    {
        return implode(', ', array_filter($parts, fn (?string $part): bool => filled($part)));
    }
}
