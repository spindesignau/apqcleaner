<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Northern Beaches (and nearby) suburbs with postcodes, plus spellings seen in the old spreadsheet.
 * Used to pull the suburb off the end of free-typed addresses.
 */
class Suburbs
{
    public const POSTCODES = [
        'Manly' => '2095', 'North Manly' => '2100', 'Manly Vale' => '2093', 'Fairlight' => '2094',
        'Balgowlah' => '2093', 'Balgowlah Heights' => '2093', 'Clontarf' => '2093', 'Seaforth' => '2092',
        'Queenscliff' => '2096', 'Freshwater' => '2096', 'Curl Curl' => '2096', 'North Curl Curl' => '2099',
        'Dee Why' => '2099', 'Narraweena' => '2099', 'Cromer' => '2099', 'Collaroy' => '2097',
        'Collaroy Plateau' => '2097', 'Wheeler Heights' => '2097', 'Narrabeen' => '2101',
        'North Narrabeen' => '2101', 'Elanora Heights' => '2101', 'Ingleside' => '2101', 'Warriewood' => '2102',
        'Mona Vale' => '2103', 'Bayview' => '2104', 'Church Point' => '2105', 'Newport' => '2106',
        'Avalon Beach' => '2107', 'Bilgola' => '2107', 'Clareville' => '2107', 'Whale Beach' => '2107',
        'Palm Beach' => '2108', 'Brookvale' => '2100', 'Beacon Hill' => '2100', 'Allambie Heights' => '2100',
        'Oxford Falls' => '2100', 'Frenchs Forest' => '2086', 'Forestville' => '2087', 'Killarney Heights' => '2087',
        'Belrose' => '2085', 'Davidson' => '2085', 'Terrey Hills' => '2084', 'Mosman' => '2088',
        'Cremorne' => '2090', 'Neutral Bay' => '2089', 'North Sydney' => '2060', 'St Leonards' => '2065',
        'Artarmon' => '2064', 'Willoughby' => '2068', 'Lane Cove West' => '2066', 'Randwick' => '2031',
        'Darlinghurst' => '2010', 'Dundas Valley' => '2117',
    ];

    public const ALIASES = [
        'nort manly' => 'North Manly', 'maly' => 'Manly', 'fairlinght' => 'Fairlight',
        'queencliff' => 'Queenscliff', 'qeenscliff' => 'Queenscliff', 'queens cliff' => 'Queenscliff',
        'dhy why' => 'Dee Why', 'dee way' => 'Dee Why', 'balgolah' => 'Balgowlah',
        'bslgowlah heihts' => 'Balgowlah Heights', 'elanoa heights' => 'Elanora Heights',
        'artamon' => 'Artarmon', 'st leonard´s' => 'St Leonards', 'st leonard\'s' => 'St Leonards',
        'avalon' => 'Avalon Beach',
    ];

    /**
     * @return array{0: string, 1: ?string, 2: ?string} [street, suburb, postcode]
     */
    public static function split(string $address): array
    {
        $postcode = preg_match('/\b(2\d{3})\b/', $address, $m) ? $m[1] : null;
        $rest = preg_replace(['/\b2\d{3}\b/', '/\bAustralia\b/i', '/(?<=[a-z])NSW\b/i', '/\bN\.?S\.?W\.?\b/i', '/\(.*?\)/'], ['', '', '', '', ''], $address);
        $rest = trim(preg_replace('/\s+/', ' ', $rest), ' ,.');

        $needle = Str::lower($rest);
        $candidates = array_merge(
            array_combine(array_map('strtolower', array_keys(self::POSTCODES)), array_keys(self::POSTCODES)),
            self::ALIASES,
        );
        uksort($candidates, fn ($a, $b) => strlen($b) <=> strlen($a));   // longest first: "north manly" before "manly"

        foreach ($candidates as $spelling => $suburb) {
            if (preg_match('/(^|[\s,.])'.preg_quote($spelling, '/').'$/u', $needle)) {
                $street = trim(mb_substr($rest, 0, mb_strlen($rest) - mb_strlen($spelling)), ' ,.');

                return [self::tidy($street), $suburb, $postcode ?? self::POSTCODES[$suburb] ?? null];
            }
        }

        if (str_contains($rest, ',')) {
            return [self::tidy(Str::beforeLast($rest, ',')), Str::title(Str::lower(trim(Str::afterLast($rest, ',')))) ?: null, $postcode];
        }

        return [self::tidy($rest), null, $postcode];
    }

    private static function tidy(string $street): string
    {
        $street = trim(preg_replace('/\s+/', ' ', $street), ' ,');

        // Only re-case shouty or all-lowercase input; keep "McDonald" etc. as typed.
        return $street === Str::upper($street) || $street === Str::lower($street) ? Str::title(Str::lower($street)) : $street;
    }
}
