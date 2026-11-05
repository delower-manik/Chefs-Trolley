<?php

namespace Database\Seeders;

use App\Models\PaymentGatewayConfig;
use Illuminate\Database\Seeder;

class PaymentGatewaysSeeder extends Seeder
{
    /**
     * Seeds the fixed catalog of supported gateways, all disabled and
     * unconfigured by default. Actually processing a payment through any
     * of these needs its credentials added in Integrations → Payments (Phase 15b).
     */
    public function run(): void
    {
        $gateways = [
            ['gateway_key' => 'cash_on_delivery', 'display_name' => 'Cash on Delivery'],
            ['gateway_key' => 'bkash', 'display_name' => 'bKash'],
            ['gateway_key' => 'nagad', 'display_name' => 'Nagad'],
            ['gateway_key' => 'sslcommerz', 'display_name' => 'SSLCommerz'],
            ['gateway_key' => 'eps', 'display_name' => 'EPS'],
            ['gateway_key' => 'aamarpay', 'display_name' => 'aamarPay'],
            ['gateway_key' => 'bangla_qr', 'display_name' => 'Bangla QR'],
            ['gateway_key' => 'piprapay', 'display_name' => 'Pipra Pay'],
        ];

        foreach ($gateways as $gateway) {
            PaymentGatewayConfig::firstOrCreate(['gateway_key' => $gateway['gateway_key']], $gateway);
        }
    }
}
