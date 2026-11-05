<?php

namespace App\Services\Notify;

class SmsNumber
{
    /** Normalises a Bangladeshi mobile number to 8801XXXXXXXXX, or null if it isn't one. */
    public static function bd(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '01')) {
            $digits = '88'.$digits;
        } elseif (str_starts_with($digits, '1') && strlen($digits) === 10) {
            $digits = '880'.$digits;
        }

        return preg_match('/^8801[3-9]\d{8}$/', $digits) ? $digits : null;
    }
}
