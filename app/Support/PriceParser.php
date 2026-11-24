<?php

namespace App\Support;

final class PriceParser
{
    /**
     * Parse "12.5", "$12.50", "1,234.00", " 12.50 " into a 2 dp decimal string, or null.
     */
    public static function parse(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = str_replace([',', '$'], '', trim($value));

        if ($clean === '' || ! preg_match('/^\d+(\.\d+)?$|^\.\d+$/', $clean)) {
            return null;
        }

        return number_format((float) $clean, 2, '.', '');
    }
}
