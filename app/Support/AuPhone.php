<?php

namespace App\Support;

/**
 * Normalises Australian phone numbers to E.164 (+61…). Non-AU international numbers are kept as +<digits>.
 */
class AuPhone
{
    public static function normalise(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $raw);
        $hadPlus = str_contains($raw, '+');

        return match (true) {
            (bool) preg_match('/^0([2-478]\d{8})$/', $digits, $m) => '+61'.$m[1],     // 04xx… / 02 9…
            (bool) preg_match('/^([2-478]\d{8})$/', $digits, $m) => '+61'.$m[1],      // leading 0 lost in a spreadsheet
            (bool) preg_match('/^61([2-478]\d{8})$/', $digits, $m) => '+61'.$m[1],
            $hadPlus || strlen($digits) >= 11 => '+'.$digits,                          // international, e.g. UK 44…
            default => $raw,                                                           // leave for a human to fix
        };
    }

    public static function isMobile(?string $e164): bool
    {
        return (bool) preg_match('/^\+614\d{8}$/', (string) $e164);
    }

    public static function display(?string $e164): ?string
    {
        if ($e164 && preg_match('/^\+614(\d{2})(\d{3})(\d{3})$/', $e164, $m)) {
            return "04{$m[1]} {$m[2]} {$m[3]}";
        }
        if ($e164 && preg_match('/^\+61([2378])(\d{4})(\d{4})$/', $e164, $m)) {
            return "(0{$m[1]}) {$m[2]} {$m[3]}";
        }

        return $e164;
    }
}
