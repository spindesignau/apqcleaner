<?php

namespace Tests\Unit;

use App\Support\AuPhone;
use App\Support\Suburbs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AddressAndPhoneTest extends TestCase
{
    #[DataProvider('phones')]
    public function test_phone_normalisation(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, AuPhone::normalise($raw));
    }

    public static function phones(): array
    {
        return [
            'spreadsheet lost leading zero' => ['412345678', '+61412345678'],
            'spaced mobile' => ['0412 345 678', '+61412345678'],
            'already E.164' => ['+61 412 345 678', '+61412345678'],
            'landline' => ['(02) 9977 1234', '+61299771234'],
            'uk number' => ['44 7528389679', '+447528389679'],
            'bracketed intl' => ['(+65) 82800575', '+6582800575'],
            'empty' => ['  ', null],
        ];
    }

    #[DataProvider('addresses')]
    public function test_address_split(string $raw, array $expected): void
    {
        $this->assertSame($expected, Suburbs::split($raw));
    }

    public static function addresses(): array
    {
        return [
            'comma + state' => ['12 Example Street, North Manly NSW', ['12 Example Street', 'North Manly', '2100']],
            'no comma, postcode' => ['3 Sample St Freshwater NSW 2096', ['3 Sample St', 'Freshwater', '2096']],
            'misspelt suburb' => ['8 Test Ave, Fairlinght', ['8 Test Ave', 'Fairlight', '2094']],
            'shouty, glued NSW' => ['5 DEMO RD, QUEENCLIFFNSW', ['5 Demo Rd', 'Queenscliff', '2096']],
            'Australia suffix' => ['1/9 Fake St Dee Why Australia', ['1/9 Fake St', 'Dee Why', '2099']],
            'unknown suburb' => ['7 Nowhere Lane', ['7 Nowhere Lane', null, null]],
        ];
    }
}
