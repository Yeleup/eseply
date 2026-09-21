<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Выбранные для массовой печати квитанции хранятся в кэше под случайным
 * токеном, чтобы не передавать тысячи id в адресе страницы печати.
 * Токен действует ограниченное время и открывается только тем же
 * пользователем в той же организации.
 */
class ReceiptPrintSelection
{
    public const int LIFETIME = 1800;

    /**
     * @param  iterable<int|string>  $receiptIds
     */
    public static function store(User $user, Organization $organization, iterable $receiptIds): string
    {
        $token = Str::random(40);

        Cache::put(self::key($token), [
            'user_id' => (int) $user->getKey(),
            'organization_id' => (int) $organization->getKey(),
            'receipt_ids' => collect($receiptIds)
                ->map(fn (int|string $receiptId): int => (int) $receiptId)
                ->filter(fn (int $receiptId): bool => $receiptId > 0)
                ->unique()
                ->values()
                ->all(),
        ], self::LIFETIME);

        return $token;
    }

    /**
     * @return Collection<int, int>|null
     */
    public static function resolve(string $token, User $user, Organization $organization): ?Collection
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return null;
        }

        $selection = Cache::get(self::key($token));

        if (
            ! is_array($selection)
            || ($selection['user_id'] ?? null) !== (int) $user->getKey()
            || ($selection['organization_id'] ?? null) !== (int) $organization->getKey()
        ) {
            return null;
        }

        return collect($selection['receipt_ids'] ?? []);
    }

    private static function key(string $token): string
    {
        return "receipt-print-selection:{$token}";
    }
}
