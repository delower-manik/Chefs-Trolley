<?php

namespace App\Services\Gateways\Drivers;

use App\Models\PaymentSession;
use App\Services\Gateways\GatewayDriver;
use App\Services\Gateways\InitResult;
use App\Services\Gateways\VerifyResult;

/**
 * Bangla QR is the EMVCo merchant-presented QR standard. A restaurant gets its merchant-account block from its bank;
 * this driver wraps it with the amount, an order reference and the CRC so any bank / MFS app can scan it.
 * There is NO bank API behind it here, so nothing can confirm the payment automatically: a person checks the
 * merchant app or SMS and confirms it (recorded as manually confirmed, not verified by the bank).
 */
class BanglaQr implements GatewayDriver
{
    public static function key(): string
    {
        return 'bangla_qr';
    }

    public static function label(): string
    {
        return 'Bangla QR';
    }

    public static function manual(): bool
    {
        return true;
    }

    public static function fields(): array
    {
        return [
            ['key' => 'merchant_name', 'label' => 'Merchant name (max 25 chars)', 'secret' => false, 'required' => true],
            ['key' => 'merchant_city', 'label' => 'City (max 15 chars)', 'secret' => false, 'required' => true],
            ['key' => 'account_template', 'label' => 'Merchant account block from your bank (raw EMV text, tags 26–51)', 'secret' => false, 'required' => true, 'type' => 'textarea'],
            ['key' => 'mcc', 'label' => 'Merchant category code (default 5812)', 'secret' => false, 'required' => false],
        ];
    }

    public function initiate(PaymentSession $session, array $config, string $callbackUrl, array $customer): InitResult
    {
        $template = preg_replace('/\s+/', '', (string) $config['account_template']);
        if (! preg_match('/^(?:2[6-9]|3\d|4\d|5[01])\d{2}[\x21-\x7E]+$/', $template)) {
            return InitResult::failed('The merchant account block from your bank does not look like EMV tags 26–51.');
        }
        $ref = strtoupper(substr(str_replace('-', '', $session->id), 0, 12));

        return InitResult::ok($ref, null, self::payload($config, $template, number_format((float) $session->amount, 2, '.', ''), $ref));
    }

    public function verify(PaymentSession $session, array $config, array $callback): VerifyResult
    {
        return VerifyResult::pending('Bangla QR payments are confirmed by a person.');
    }

    public static function payload(array $config, string $template, string $amount, string $ref): string
    {
        $tlv = fn (string $id, string $value) => $id.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;

        $body = $tlv('00', '01').$tlv('01', '12').$template
            .$tlv('52', preg_match('/^\d{4}$/', (string) ($config['mcc'] ?? '')) ? (string) $config['mcc'] : '5812')
            .$tlv('53', '050').$tlv('54', $amount).$tlv('58', 'BD')
            .$tlv('59', substr((string) $config['merchant_name'], 0, 25)).$tlv('60', substr((string) $config['merchant_city'], 0, 15))
            .$tlv('62', $tlv('01', $ref)).'6304';

        return $body.self::crc16($body);
    }

    /** CRC-16/CCITT-FALSE (poly 0x1021, init 0xFFFF), as EMVCo QR requires; upper-case 4-digit hex. */
    public static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        foreach (str_split($data) as $char) {
            $crc ^= ord($char) << 8;
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
