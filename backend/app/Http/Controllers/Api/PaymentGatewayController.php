<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavePaymentGatewayConfigRequest;
use App\Models\AuditLog;
use App\Models\PaymentGatewayConfig;
use App\Services\Gateways\GatewayManager;

/**
 * Configuration management only -- see PaymentGatewayConfig's docblock and
 * README. Gateways come from a fixed, seeded catalog (PaymentGatewaySeeder)
 * rather than allowing arbitrary gateway_key creation through the API,
 * since these represent real, specific integrations that would each need
 * their own SDK wiring to actually function.
 */
class PaymentGatewayController extends Controller
{
    public function index(GatewayManager $manager)
    {
        $manager->ensureCatalog();
        $gateways = PaymentGatewayConfig::orderBy('display_name')->get();

        return response()->json(['data' => $gateways->map($this->serialize(...))]);
    }

    public function update(SavePaymentGatewayConfigRequest $request, PaymentGatewayConfig $paymentGatewayConfig)
    {
        $before = ['isEnabled' => $paymentGatewayConfig->is_enabled];
        $data = $request->validated();

        // Only the fields this gateway really has are kept, and a blank value leaves the saved one alone,
        // so a secret that the screen never shows back can't be wiped by saving the form.
        if (array_key_exists('config', $data)) {
            $class = GatewayManager::DRIVERS[$paymentGatewayConfig->gateway_key] ?? null;
            $allowed = $class ? collect($class::fields())->pluck('key')->all() : null;
            $merged = $paymentGatewayConfig->config ?? [];
            foreach ((array) $data['config'] as $key => $value) {
                if ($allowed !== null && ! in_array($key, $allowed, true)) {
                    continue;
                }
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $merged[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                }
            }
            $data['config'] = $merged;
        }

        // Turning an online gateway on without its credentials would only produce failed payments.
        if (($data['is_enabled'] ?? false) === true && isset(GatewayManager::DRIVERS[$paymentGatewayConfig->gateway_key])) {
            $cfg = $data['config'] ?? $paymentGatewayConfig->config ?? [];
            foreach (GatewayManager::DRIVERS[$paymentGatewayConfig->gateway_key]::fields() as $f) {
                if ($f['required'] && trim((string) ($cfg[$f['key']] ?? '')) === '') {
                    return response()->json(['code' => 'credentials_missing', 'message' => "Fill in \"{$f['label']}\" before enabling {$paymentGatewayConfig->display_name}."], 422);
                }
            }
        }
        $paymentGatewayConfig->update($data);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'payment_gateway.updated',
            subjectType: PaymentGatewayConfig::class,
            subjectId: $paymentGatewayConfig->id,
            changes: ['before' => $before, 'after' => ['isEnabled' => $paymentGatewayConfig->fresh()->is_enabled]],
            ipAddress: $request->ip(),
        );
        // Note: credential values are deliberately never written to the audit log.

        return response()->json(['data' => $this->serialize($paymentGatewayConfig->fresh())]);
    }

    private function serialize(PaymentGatewayConfig $gateway): array
    {
        $class = GatewayManager::DRIVERS[$gateway->gateway_key] ?? null;

        return [
            'id' => $gateway->id,
            'gatewayKey' => $gateway->gateway_key,
            'displayName' => $gateway->display_name,
            'isEnabled' => $gateway->is_enabled,
            // Whether credentials exist is exposed; the credentials themselves never are.
            'isConfigured' => ! empty($gateway->config),
            'online' => $class !== null,
            'manual' => $class ? $class::manual() : false,
            // Non-secret values are shown back so they can be checked; secrets only say whether they are set.
            'fields' => collect($class ? $class::fields() : [])->map(fn ($f) => [
                'key' => $f['key'], 'label' => $f['label'], 'secret' => $f['secret'], 'required' => $f['required'], 'type' => $f['type'] ?? 'text',
                'isSet' => trim((string) (($gateway->config ?? [])[$f['key']] ?? '')) !== '',
                'value' => $f['secret'] ? null : (($gateway->config ?? [])[$f['key']] ?? null),
            ])->values(),
        ];
    }
}
