<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

final class MeterReadingPhotoStorage
{
    private const DISK = 'public';

    private const DIRECTORY = 'meter-reading-photos';

    public static function disk(): string
    {
        return self::DISK;
    }

    public static function directoryFor(int|string $organizationId): string
    {
        return self::DIRECTORY."/{$organizationId}";
    }

    public static function delete(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }

    /**
     * @param  array<int, string|null>  $paths
     */
    public static function deleteMany(array $paths): void
    {
        $paths = array_values(array_filter($paths, fn (?string $path): bool => filled($path)));

        if ($paths === []) {
            return;
        }

        Storage::disk(self::DISK)->delete($paths);
    }

    public static function deleteOrganizationDirectory(int|string $organizationId): void
    {
        Storage::disk(self::DISK)->deleteDirectory(self::directoryFor($organizationId));
    }
}
