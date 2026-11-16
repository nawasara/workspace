<?php

namespace Nawasara\Workspace\Support;

/**
 * Quota sizes as people write them ("3 GB") and as Nextcloud stores them
 * (bytes). Binary units (1 GB = 1024^3), the same as Nextcloud's own
 * `human_file_size` / `computerFileSize`, so "3 GB" set over OCS and 3 GB
 * shown in the Nextcloud admin page are the same number.
 */
final class QuotaSize
{
    private const UNITS = ['TB' => 1024 ** 4, 'GB' => 1024 ** 3, 'MB' => 1024 ** 2];

    /** "50 GB" → bytes. */
    public static function bytes(string $size): int
    {
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*(TB|GB|MB)\s*$/i', $size, $m) !== 1) {
            throw new \InvalidArgumentException("Ukuran kuota tidak dikenal: {$size}");
        }

        return (int) round((float) $m[1] * self::UNITS[strtoupper($m[2])]);
    }

    /**
     * Bytes → the largest whole unit ("5 GB", "1536 MB"). Whole units only, so
     * the string sent to Nextcloud never carries a rounding error.
     */
    public static function format(int $bytes): string
    {
        foreach (self::UNITS as $unit => $size) {
            if ($bytes >= $size && $bytes % $size === 0) {
                return intdiv($bytes, $size).' '.$unit;
            }
        }

        return intdiv($bytes, self::UNITS['MB']).' MB';
    }
}
