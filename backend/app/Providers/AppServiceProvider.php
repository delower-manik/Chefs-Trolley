<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\DeliveryAssignment;
use App\Models\Order;
use App\Models\Review;
use App\Observers\DeliveryAssignmentObserver;
use App\Observers\OrderObserver;
use App\Observers\WebhookEventObserver;
use App\Services\AppSettings;
use App\Services\Realtime\Publisher;
use App\Services\Realtime\Realtime;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Publisher::class, fn () => Realtime::make());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->singleton(AppSettings::class);
        $this->configureRateLimiting();

        Order::observe(OrderObserver::class);
        DeliveryAssignment::observe(DeliveryAssignmentObserver::class);
        Review::observe(WebhookEventObserver::class);
        Customer::observe(WebhookEventObserver::class);
    }

    private function configureRateLimiting(): void
    {
        // Credential endpoints: throttle per (email, IP) so one attacker cannot
        // lock a victim out from everywhere, plus a looser per-IP ceiling that
        // slows credential stuffing across many accounts.
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('login:'.$email.'|'.$request->ip()),
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
            ];
        });

        // Refresh tokens are long-lived bearer secrets; guessing is pointless
        // but cheap to throttle.
        // The gift-card code is the secret; slow down anyone guessing at the till endpoint.
        RateLimiter::for('giftcard-lookup', fn (Request $request) => Limit::perMinute(15)->by('gc:'.$request->user()?->id.':'.$request->ip()));
        // Second sign-in step: per challenge-holder IP; the challenge itself also dies after five wrong codes.
        RateLimiter::for('mfa', fn (Request $request) => Limit::perMinute(10)->by('mfa:'.$request->ip()));
        // Everything behind login: a generous ceiling per user (per IP when unauthenticated) so a runaway client or a
        // stolen token can't hammer the API. Normal POS use is far below this.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->id ?: 'ip:'.$request->ip()));
        // Sensitive self-service actions (password change, MFA proof) — slows anyone guessing the current password/code.
        RateLimiter::for('account-sensitive', fn (Request $request) => Limit::perMinute(10)->by('acct:'.$request->user()?->id));
        // Customer website (Phase 19): public reads per IP, code requests tightly, signed-in customers per account.
        RateLimiter::for('web-public', fn (Request $request) => Limit::perMinute(120)->by('web:'.$request->ip()));
        RateLimiter::for('web-otp', fn (Request $request) => Limit::perMinute(20)->by('webotp:'.$request->ip()));
        RateLimiter::for('web-customer', fn (Request $request) => Limit::perMinute(90)->by('webc:'.($request->bearerToken() ? hash('sha256', $request->bearerToken()) : $request->ip())));
        RateLimiter::for('web-order', fn (Request $request) => Limit::perMinute(6)->by('weborder:'.($request->bearerToken() ? hash('sha256', $request->bearerToken()) : $request->ip())));
        RateLimiter::for('refresh', fn (Request $request) => Limit::perMinute(20)->by('refresh:'.$request->ip()));
    }
}
