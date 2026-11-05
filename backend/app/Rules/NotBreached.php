<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;

/** Rejects passwords that appear in known breaches (k-anonymity: only the first 5 chars of the SHA-1 leave the server). */
class NotBreached implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! config('security.breach_check') || ! is_string($value) || $value === '') {
            return;
        }
        $sha = strtoupper(sha1($value));
        try {
            $res = Http::timeout(3)->withHeaders(['Add-Padding' => 'true', 'User-Agent' => 'chefs-trolley'])->get('https://api.pwnedpasswords.com/range/'.substr($sha, 0, 5));
            if (! $res->successful()) {
                return; // can't tell — never block on an outage
            }
            foreach (preg_split('/\r?\n/', $res->body()) as $line) {
                [$suffix, $count] = array_pad(explode(':', trim($line), 2), 2, '0');
                if (hash_equals(substr($sha, 5), strtoupper($suffix)) && (int) $count > 0) {
                    $fail('This password has appeared in a known data breach. Choose a different one.');

                    return;
                }
            }
        } catch (\Throwable) {
            // fail open
        }
    }
}
