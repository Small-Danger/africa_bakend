<?php

namespace App\Support;

class WhatsAppPhoneValidator
{
    /** Préfixes mobile Burkina Faso (ARCEP — Moov, Orange, Telecel) */
    private const BF_MOBILE_PREFIXES = [
        '01', '02', '03', '04', '05', '06', '07',
        '50', '51', '52', '53', '54', '55', '56', '57', '58',
        '60', '61', '62', '63', '64', '65', '66', '67', '68', '69',
        '70', '71', '72', '73', '74', '75', '76', '77', '78', '79',
    ];

    /** @var array<string, array{lengths: int[], keep_zero?: bool}> */
    private const COUNTRY_LENGTHS = [
        '226' => ['lengths' => [8]],
        '212' => ['lengths' => [9]],
            '225' => ['lengths' => [9]],
        '221' => ['lengths' => [9]],
        '223' => ['lengths' => [8]],
        '227' => ['lengths' => [8]],
        '228' => ['lengths' => [8]],
        '229' => ['lengths' => [10], 'keep_zero' => true], // Bénin : 01 fait partie du numéro
        '224' => ['lengths' => [9]],
        '233' => ['lengths' => [9]],
        '237' => ['lengths' => [9]],
        '33'  => ['lengths' => [9]],
        '32'  => ['lengths' => [9]],
        '1'   => ['lengths' => [10]],
    ];

    public static function normalize(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $clean = preg_replace('/\s+/', '', trim($phone));

        return $clean !== '' ? $clean : null;
    }

    public static function isValid(?string $phone): bool
    {
        $normalized = self::normalize($phone);

        if ($normalized === null || ! str_starts_with($normalized, '+')) {
            return false;
        }

        $digits = preg_replace('/\D/', '', substr($normalized, 1));

        if ($digits === null || strlen($digits) < 8 || strlen($digits) > 15) {
            return false;
        }

        if (preg_match('/^(\d)\1+$/', $digits)) {
            return false;
        }

        // PHP convertit les clés numériques en int : on force le type string
        $dials = array_map('strval', array_keys(self::COUNTRY_LENGTHS));
        usort($dials, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($dials as $dial) {
            if (! str_starts_with($digits, $dial)) {
                continue;
            }

            $rules = self::COUNTRY_LENGTHS[$dial];
            $national = substr($digits, strlen($dial));

            if ($dial === '226') {
                $national = self::normalizeBurkinaNational($national);
            } elseif (empty($rules['keep_zero'])) {
                $national = ltrim($national, '0');
            }

            if (! in_array(strlen($national), $rules['lengths'], true)) {
                return false;
            }

            return self::isValidNational($dial, $national);
        }

        return false;
    }

    private static function isValidNational(string $dial, string $national): bool
    {
        return match ($dial) {
            '226' => self::hasPrefix2($national, self::BF_MOBILE_PREFIXES),
            '212' => preg_match('/^[67]\d{8}$/', $national) === 1,
            '225' => preg_match('/^[157]\d{8}$/', $national) === 1,
            '221' => preg_match('/^(70|75|76|77|78)\d{7}$/', $national) === 1,
            '223' => self::isMaliMobile($national),
            '227' => preg_match('/^(93|94|96)\d{6}$/', $national) === 1,
            '228' => self::isTogoMobile($national),
            '229' => self::isBeninMobile($national),
            '224' => preg_match('/^6\d{8}$/', $national) === 1,
            '233' => self::isGhanaMobile($national),
            '237' => preg_match('/^6\d{8}$/', $national) === 1,
            '33'  => preg_match('/^[67]\d{8}$/', $national) === 1,
            '32'  => preg_match('/^4[5-9]\d{7}$/', $national) === 1,
            '1'   => preg_match('/^[2-9]\d{9}$/', $national) === 1,
            default => false,
        };
    }

    private static function hasPrefix2(string $national, array $prefixes): bool
    {
        if (strlen($national) < 2) {
            return false;
        }

        return in_array(substr($national, 0, 2), $prefixes, true);
    }

    /** 01–07 = 8 chiffres ; 0+63… = 9 chiffres avec 0 tronc local */
    private static function normalizeBurkinaNational(string $digits): string
    {
        if (strlen($digits) <= 8) {
            return $digits;
        }

        if (strlen($digits) === 9 && str_starts_with($digits, '0')) {
            $withoutTrunk = substr($digits, 1);
            if (self::hasPrefix2($withoutTrunk, self::BF_MOBILE_PREFIXES)) {
                return $withoutTrunk;
            }
            $first8 = substr($digits, 0, 8);
            if (self::hasPrefix2($first8, self::BF_MOBILE_PREFIXES)) {
                return $first8;
            }
        }

        return substr($digits, 0, 8);
    }

    private static function isMaliMobile(string $national): bool
    {
        if (preg_match('/^2[0-7]\d{6}$/', $national)) {
            return false;
        }

        return preg_match('/^(6[5-9]|7\d|8[2-4]|9\d)\d{6}$/', $national) === 1;
    }

    private static function isTogoMobile(string $national): bool
    {
        if (preg_match('/^2[2-7]\d{6}$/', $national)) {
            return false;
        }

        $p3 = substr($national, 0, 3);
        $p2 = substr($national, 0, 2);

        $prefixes3 = ['700', '701', '702', '703', '704', '705', '793', '794', '795', '796', '797', '798', '799'];
        if (in_array($p3, $prefixes3, true)) {
            return true;
        }

        $prefixes2 = ['70', '71', '72', '73', '78', '79', '90', '91', '92', '93', '96', '97', '98', '99'];

        return in_array($p2, $prefixes2, true);
    }

    private static function isBeninMobile(string $national): bool
    {
        if (! str_starts_with($national, '01') || strlen($national) !== 10) {
            return false;
        }

        $sub = substr($national, 2, 2);
        $landline = ['20', '21', '22', '23'];
        if (in_array($sub, $landline, true)) {
            return false;
        }

        $mobileSub = [
            '40', '41', '42', '43', '44', '45', '46', '47',
            '50', '51', '52', '53', '54', '55', '56', '57', '58', '59',
            '60', '61', '62', '63', '64', '65', '66', '67', '68', '69',
            '90', '91', '93', '94', '95', '96', '97', '98', '99',
        ];

        return in_array($sub, $mobileSub, true);
    }

    private static function isGhanaMobile(string $national): bool
    {
        if (preg_match('/^3[0-8]\d{7}$/', $national)) {
            return false;
        }

        $prefixes = ['20', '23', '24', '25', '26', '27', '28', '50', '53', '54', '55', '56', '57', '59'];

        return self::hasPrefix2($national, $prefixes);
    }
}
