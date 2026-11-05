<?php

namespace App\Services\Gateways;

use App\Models\PaymentGatewayConfig;
use App\Services\Gateways\Drivers\AamarPay;
use App\Services\Gateways\Drivers\BanglaQr;
use App\Services\Gateways\Drivers\Bkash;
use App\Services\Gateways\Drivers\Eps;
use App\Services\Gateways\Drivers\Nagad;
use App\Services\Gateways\Drivers\PipraPay;
use App\Services\Gateways\Drivers\SslCommerz;

/** The catalog of online gateways, and the one place credentials are read from their encrypted store. */
class GatewayManager
{
    /** @var array<string, class-string<GatewayDriver>> */
    public const DRIVERS = [
        'bkash' => Bkash::class,
        'nagad' => Nagad::class,
        'sslcommerz' => SslCommerz::class,
        'eps' => Eps::class,
        'aamarpay' => AamarPay::class,
        'bangla_qr' => BanglaQr::class,
        'piprapay' => PipraPay::class,
    ];

    public function driver(string $key): ?GatewayDriver
    {
        $class = self::DRIVERS[$key] ?? null;

        return $class ? app($class) : null;
    }

    /** Create any catalog rows an older install is missing (idempotent). */
    public function ensureCatalog(): void
    {
        PaymentGatewayConfig::firstOrCreate(['gateway_key' => 'cash_on_delivery'], ['display_name' => 'Cash on Delivery']);
        foreach (self::DRIVERS as $key => $class) {
            PaymentGatewayConfig::firstOrCreate(['gateway_key' => $key], ['display_name' => $class::label()]);
        }
    }

    /** @return array<string, mixed>|null credentials, only when the gateway is enabled and every required field is filled */
    public function usableConfig(string $key): ?array
    {
        $class = self::DRIVERS[$key] ?? null;
        $row = $class ? PaymentGatewayConfig::where('gateway_key', $key)->first() : null;
        if (! $row || ! $row->is_enabled) {
            return null;
        }
        $config = $row->config ?? [];
        foreach ($class::fields() as $field) {
            if ($field['required'] && trim((string) ($config[$field['key']] ?? '')) === '') {
                return null;
            }
        }

        return $config;
    }

    public static function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
