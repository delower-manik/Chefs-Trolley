<?php

namespace App\Support;

/** Bangladeshi mobile numbers: accepts 01XXXXXXXXX, 8801XXXXXXXXX, +8801XXXXXXXXX (spaces/dashes ignored, Bengali digits too). */
class BdPhone
{
    /** @return string|null the canonical 11-digit local form (01XXXXXXXXX), or null if it isn't a valid mobile number */
    public static function normalize(string $raw): ?string
    {
        $s = strtr(trim($raw), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        $s = preg_replace('/[\s\-().]/', '', $s) ?? '';
        if (str_starts_with($s, '+880')) {
            $s = '0'.substr($s, 4);
        } elseif (str_starts_with($s, '880')) {
            $s = '0'.substr($s, 3);
        }

        return preg_match('/^01[3-9]\d{8}$/', $s) ? $s : null;
    }

    /** Every spelling an existing customer row might use for this number. */
    public static function variants(string $local): array
    {
        return [$local, '880'.substr($local, 1), '+880'.substr($local, 1)];
    }
}
