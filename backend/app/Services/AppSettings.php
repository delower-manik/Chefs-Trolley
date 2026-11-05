<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Business-wide settings: a fixed list of keys (nothing arbitrary can be stored), each with a type, a default
 * and a validation rule. Values are cached per request; the table only holds what an admin actually changed.
 */
class AppSettings
{
    /** @return array<string, array{type: string, default: mixed, rules: array, group: string}> */
    public static function schema(): array
    {
        return [
            'business.name' => ['group' => 'business', 'type' => 'string', 'default' => 'Chefs Trolley', 'rules' => ['required', 'string', 'max:120']],
            'business.legal_name' => ['group' => 'business', 'type' => 'string', 'default' => '', 'rules' => ['nullable', 'string', 'max:160']],
            'business.address' => ['group' => 'business', 'type' => 'string', 'default' => '', 'rules' => ['nullable', 'string', 'max:300']],
            'business.phone' => ['group' => 'business', 'type' => 'string', 'default' => '', 'rules' => ['nullable', 'string', 'max:40']],
            'business.email' => ['group' => 'business', 'type' => 'string', 'default' => '', 'rules' => ['nullable', 'email', 'max:160']],
            'business.tax_id' => ['group' => 'business', 'type' => 'string', 'default' => '', 'rules' => ['nullable', 'string', 'max:40']], // BIN / VAT registration

            'locale.default' => ['group' => 'locale', 'type' => 'string', 'default' => 'en', 'rules' => ['required', Rule::in(['en', 'bn'])]],
            'locale.timezone' => ['group' => 'locale', 'type' => 'string', 'default' => 'Asia/Dhaka', 'rules' => ['required', 'timezone:all']],

            // Display only (decimals = how many are shown on screen; receipts always show 2). Money is stored and calculated in BDT.
            'currency.code' => ['group' => 'currency', 'type' => 'string', 'default' => 'BDT', 'rules' => ['required', 'string', 'regex:/^[A-Z]{3}$/']],
            'currency.symbol' => ['group' => 'currency', 'type' => 'string', 'default' => '৳', 'rules' => ['required', 'string', 'max:5']],
            'currency.decimals' => ['group' => 'currency', 'type' => 'int', 'default' => 0, 'rules' => ['required', 'integer', 'between:0,2']],

            'receipt.language' => ['group' => 'receipt', 'type' => 'string', 'default' => 'en', 'rules' => ['required', Rule::in(['en', 'bn'])]],
            'receipt.header' => ['group' => 'receipt', 'type' => 'string', 'default' => '', 'rules' => ['nullable', 'string', 'max:200']],
            'receipt.footer' => ['group' => 'receipt', 'type' => 'string', 'default' => '', 'rules' => ['nullable', 'string', 'max:300']],
            'receipt.show_tax_id' => ['group' => 'receipt', 'type' => 'bool', 'default' => true, 'rules' => ['boolean']],

            // Customer website (Phase 19). Ordering stays OFF until the owner switches it on.
            'web.ordering' => ['group' => 'web', 'type' => 'bool', 'default' => false, 'rules' => ['boolean']],
            'web.cod' => ['group' => 'web', 'type' => 'bool', 'default' => true, 'rules' => ['boolean']],
            'web.pickup' => ['group' => 'web', 'type' => 'bool', 'default' => true, 'rules' => ['boolean']],
            'web.min_order' => ['group' => 'web', 'type' => 'int', 'default' => 0, 'rules' => ['required', 'integer', 'between:0,100000']],

            // Switch whole modules off: hidden from the menu AND refused by the API.
            'features.gift_cards' => ['group' => 'features', 'type' => 'bool', 'default' => true, 'rules' => ['boolean']],
            'features.campaigns' => ['group' => 'features', 'type' => 'bool', 'default' => true, 'rules' => ['boolean']],
            'features.reservations' => ['group' => 'features', 'type' => 'bool', 'default' => true, 'rules' => ['boolean']],
            'features.wifi' => ['group' => 'features', 'type' => 'bool', 'default' => true, 'rules' => ['boolean']],
        ];
    }

    private ?array $cache = null;

    public function flush(): void
    {
        $this->cache = null;
    }

    /** @return array<string, mixed> every key with its current (or default) value */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $stored = [];
        if (Schema::hasTable('app_settings')) {
            $stored = DB::table('app_settings')->pluck('value', 'key')->all();
        }
        $out = [];
        foreach (self::schema() as $key => $def) {
            $out[$key] = array_key_exists($key, $stored) ? $this->decode($stored[$key], $def) : $def['default'];
        }

        return $this->cache = $out;
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        return $this->all()[$key] ?? $fallback;
    }

    public function enabled(string $feature): bool
    {
        return (bool) $this->get('features.'.$feature, true);
    }

    /** @param array<string, mixed> $values already validated; unknown keys are ignored */
    public function set(array $values, ?string $userId): void
    {
        $schema = self::schema();
        foreach ($values as $key => $value) {
            if (! isset($schema[$key])) {
                continue;
            }
            DB::table('app_settings')->updateOrInsert(['key' => $key], [
                'value' => json_encode($this->cast($value, $schema[$key]['type'])), 'updated_by' => $userId, 'updated_at' => now(), 'created_at' => now(),
            ]);
        }
        $this->flush();
    }

    private function decode(?string $raw, array $def): mixed
    {
        return $this->cast($raw === null ? $def['default'] : json_decode($raw, true), $def['type']);
    }

    private function cast(mixed $v, string $type): mixed
    {
        return match ($type) {
            'bool' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $v,
            default => $v === null ? '' : (string) $v,
        };
    }
}
