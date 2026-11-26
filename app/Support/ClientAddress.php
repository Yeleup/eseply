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
 */
final class ClientAddress
{
    /**
     * The street, the house and the apartment, as the cash desk and the
     * reading entry show them and Kaspi sends them.
     */
    public static function of(Client $client): string
    {
        return self::join([
            $client->street?->name,
            ...self::building($client),
        ]);
    }

    /**
     * The same with the region in front, as the reports and the receipts print it.
     */
    public static function withRegion(Client $client): string
    {
        return self::join([
            $client->region?->name,
            $client->street?->name,
            ...self::building($client),
        ]);
    }

    /**
     * @return list<string|null>
     */
    private static function building(Client $client): array
    {
        return [
            filled($client->house) ? 'д. '.$client->house : null,
            filled($client->apartment) ? 'кв. '.$client->apartment : null,
        ];
    }

    /**
     * @param  list<string|null>  $parts
     */
    private static function join(array $parts): string
    {
        return implode(', ', array_filter($parts, fn (?string $part): bool => filled($part)));
    }
}
