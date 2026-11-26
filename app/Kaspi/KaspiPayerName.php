<?php

namespace App\Kaspi;

use App\ClientType;
use App\Models\Client;

/**
 * How the payer sees the subscriber on the Kaspi screen.
 *
 * A person and an individual entrepreneur — surname and initials, «Иванов И. И.»:
 * the accounts go in a row and can be tried one by one, so the full name is not
 * given out. An LLP, a commercial or a budget organization — its name in full.
 *
 * The address goes in a line of its own (`ClientAddress::of()`): the owner
 * accepted that the accounts tried one by one show surnames with addresses and
 * debts (#46).
 */
final class KaspiPayerName
{
    public static function for(Client $client): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $client->name) ?? '');

        return match ($client->client_type) {
            ClientType::Individual, ClientType::SoleProprietor => self::surnameWithInitials($name),
            default => $name,
        };
    }

    private static function surnameWithInitials(string $name): string
    {
        $words = $name === '' ? [] : explode(' ', $name);

        // «ИП Иванов Иван Иванович»: the legal form is not a part of the name.
        if (count($words) > 1 && mb_strtoupper($words[0]) === 'ИП') {
            array_shift($words);
        }

        $surname = array_shift($words);

        if ($surname === null) {
            return '';
        }

        $initials = array_map(
            fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)).'.',
            $words,
        );

        return trim($surname.' '.implode(' ', $initials));
    }
}
