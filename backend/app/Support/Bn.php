<?php

namespace App\Support;

class Bn
{
    /** Western digits → Bengali digits (০-৯). */
    public static function digits(string $s): string
    {
        return strtr($s, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']);
    }

    public static function localise(string $s, string $locale): string
    {
        return $locale === 'bn' ? self::digits($s) : $s;
    }
}
