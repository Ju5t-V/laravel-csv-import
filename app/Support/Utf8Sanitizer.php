<?php

namespace App\Support;

use Normalizer;

final class Utf8Sanitizer
{
    /**
     * Repair a CSV cell into clean UTF-8 (HANDOFF section 5.3 steps, in order).
     */
    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // 1. Strip BOM.
        $value = str_replace("\xEF\xBB\xBF", '', $value);

        // 2. Convert instead of delete: Windows-1252 to UTF-8, then drop what is still invalid.
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
            if (! mb_check_encoding($value, 'UTF-8')) {
                $value = (string) iconv('UTF-8', 'UTF-8//IGNORE', $value);
            }
        }

        // 3. Control characters except \t \n \r.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        // 4. Zero-width and replacement characters. ZWJ survives only between two
        //    non-space characters (emoji sequences).
        $value = (string) preg_replace('/[\x{200B}\x{200C}\x{FEFF}\x{FFFD}]/u', '', $value);
        $value = (string) preg_replace('/(?<!\S)\x{200D}|\x{200D}(?!\S)/u', '', $value);

        // 5. NBSP to space, then NFC when intl is available.
        $value = str_replace("\u{00A0}", ' ', $value);
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
            if ($normalized !== false) {
                $value = $normalized;
            }
        }

        // 6. Trim only the ends.
        return trim($value);
    }

    /**
     * Normalize a CSV header: no BOM or zero-width characters, trimmed, uppercase.
     */
    public static function normalizeHeader(string $header): string
    {
        $header = str_replace("\xEF\xBB\xBF", '', $header);
        $header = (string) preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $header);

        return mb_strtoupper(trim($header));
    }
}
