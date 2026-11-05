<?php

namespace App\Services\Web;

use App\Models\Customer;
use App\Models\CustomerSession;
use App\Services\AppSettings;
use App\Services\Notify\Notifier;
use App\Support\BdPhone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Phone + SMS-code sign-in for the customer website.
 *
 * - The code is 6 digits, valid 5 minutes, stored only as an HMAC (never in clear), five wrong tries kill it.
 * - Sending is limited per phone (cooldown + a few per 15 min) and per IP per day, so it can't be used to SMS-bomb a stranger
 *   or to burn the SMS balance. The reply never says whether a number already has an account.
 * - A brand-new customer is created only AFTER the code is proven; "name needed" is revealed only then.
 * - Sessions are random bearer tokens kept as sha256 in customer_sessions (separate from staff Sanctum tokens).
 */
class CustomerAuth
{
    public const TTL_MINUTES = 5;

    public const MAX_TRIES = 5;

    public const SESSION_DAYS = 30;

    public const MAX_SESSIONS = 8;

    public function __construct(private Notifier $notifier, private AppSettings $settings) {}

    /** @return 'sent'|'cooldown'|'limited' */
    public function sendCode(string $localPhone, string $ip): string
    {
        $phoneKey = 'otp:send:'.$localPhone;
        $ipKey = 'otp:ip:'.$ip.':'.now()->format('Ymd');

        if (Cache::has('otp:cooldown:'.$localPhone)) {
            return 'cooldown';
        }
        if ((int) Cache::get($phoneKey, 0) >= 3 || (int) Cache::get($ipKey, 0) >= 20) {
            return 'limited';
        }
        Cache::put($phoneKey, (int) Cache::get($phoneKey, 0) + 1, now()->addMinutes(15));
        Cache::put($ipKey, (int) Cache::get($ipKey, 0) + 1, now()->addDay());
        Cache::put('otp:cooldown:'.$localPhone, 1, now()->addSeconds(60));

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put('otp:code:'.$localPhone, ['h' => $this->hash($localPhone, $code), 'tries' => 0], now()->addMinutes(self::TTL_MINUTES));

        $name = (string) $this->settings->get('business.name');
        $this->notifier->queue('sms', $localPhone, __('web.otp_sms', ['code' => $code, 'name' => $name, 'minutes' => self::TTL_MINUTES], (string) $this->settings->get('locale.default')),
            purpose: 'otp', immediate: true);

        return 'sent';
    }

    /** @return 'ok'|'invalid'|'expired' */
    public function checkCode(string $localPhone, string $code): string
    {
        $entry = Cache::get('otp:code:'.$localPhone);
        if (! $entry) {
            return 'expired';
        }
        if ($entry['tries'] >= self::MAX_TRIES) {
            Cache::forget('otp:code:'.$localPhone);

            return 'expired';
        }
        if (! hash_equals($entry['h'], $this->hash($localPhone, $code))) {
            $entry['tries']++;
            Cache::put('otp:code:'.$localPhone, $entry, now()->addMinutes(self::TTL_MINUTES));

            return 'invalid';
        }

        return 'ok';
    }

    public function consume(string $localPhone): void
    {
        Cache::forget('otp:code:'.$localPhone);
    }

    public function findCustomer(string $localPhone): ?Customer
    {
        return Customer::whereIn('phone', BdPhone::variants($localPhone))->first();
    }

    /** @return array{0: Customer, 1: string} the customer and the plain bearer token (shown once) */
    public function startSession(Customer $customer, string $ip, ?string $userAgent): array
    {
        $customer->forceFill(['phone_verified_at' => $customer->phone_verified_at ?? now(), 'last_login_at' => now()])->save();

        $token = Str::random(60);
        CustomerSession::create([
            'customer_id' => $customer->id, 'token_hash' => hash('sha256', $token), 'ip_address' => $ip,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null, 'last_used_at' => now(), 'expires_at' => now()->addDays(self::SESSION_DAYS),
        ]);

        // Keep only the newest sessions, and drop expired ones.
        CustomerSession::where('customer_id', $customer->id)->where('expires_at', '<', now())->delete();
        $old = CustomerSession::where('customer_id', $customer->id)->latest('created_at')->skip(self::MAX_SESSIONS)->take(50)->pluck('id');
        if ($old->isNotEmpty()) {
            CustomerSession::whereIn('id', $old)->delete();
        }

        return [$customer, $token];
    }

    public function resolve(string $token): ?CustomerSession
    {
        $s = CustomerSession::with('customer')->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
        if ($s && (! $s->last_used_at || $s->last_used_at->lt(now()->subMinutes(10)))) {
            $s->forceFill(['last_used_at' => now()])->save();
        }

        return $s;
    }

    private function hash(string $phone, string $code): string
    {
        return hash_hmac('sha256', $phone.'|'.$code, (string) config('app.key'));
    }
}
