<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuration only -- no gateway SDK is wired up here to actually
 * process a payment. `config` is encrypted at rest (Laravel's
 * `encrypted:array` cast, keyed off APP_KEY) so credentials are never
 * stored in plaintext, but this table exists to hold settings a real
 * payment integration would read, not to move money.
 */
class PaymentGatewayConfig extends Model
{
    use HasUuids;

    /**
     * Mirrors the column defaults in the migration. Without this, a model
     * returned straight from create() lacks those attributes (they only exist
     * in the database row), so API responses built from it would show null
     * until the record is re-read.
     */
    protected $attributes = [
        'is_enabled' => false,
    ];

    protected $fillable = ['gateway_key', 'display_name', 'is_enabled', 'config'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'config' => 'encrypted:array',
        ];
    }
}
