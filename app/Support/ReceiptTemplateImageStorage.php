<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

final class ReceiptTemplateImageStorage
{
    private const DISK = 'public';

    private const DIRECTORY = 'receipt-templates';

    public static function disk(): string
    {
        return self::DISK;
    }

    public static function directoryFor(int|string $organizationId): string
    {
        return self::DIRECTORY."/{$organizationId}";
    }

    /**
     * Проверяет, что путь к файлу шаблона квитанции принадлежит каталогу
     * указанной организации. Пустой путь (файла нет) считается допустимым.
     * Защищает от подмены пути на файл другой организации или другого
     * модуля (например, фото показаний счётчика) через состояние формы.
     */
    public static function belongsToOrganization(?string $path, int|string $organizationId): bool
    {
        if (blank($path)) {
            return true;
        }

        if (str_contains($path, '..')) {
            return false;
        }

        return str_starts_with($path, self::directoryFor($organizationId).'/');
    }

    public static function delete(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }

    public static function url(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return Storage::disk(self::DISK)->url($path);
    }

    public static function deleteOrganizationDirectory(int|string $organizationId): void
    {
        Storage::disk(self::DISK)->deleteDirectory(self::directoryFor($organizationId));
    }
}
