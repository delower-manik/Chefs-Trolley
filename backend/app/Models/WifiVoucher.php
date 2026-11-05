<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WifiVoucher extends Model
{
    use HasUuids;

    /**
     * Mirrors the column defaults in the migration. Without this, a model
     * returned straight from create() lacks those attributes (they only exist
     * in the database row), so API responses built from it would show null
     * until the record is re-read.
     */
    protected $attributes = [
        'is_used' => false,
    ];

    protected $fillable = ['wifi_zone_id', 'code', 'duration_minutes', 'is_used', 'used_at', 'created_by'];

    protected function casts(): array
    {
        return ['is_used' => 'boolean', 'used_at' => 'datetime'];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(WifiZone::class, 'wifi_zone_id');
    }

    public static function generateCode(): string
    {
        return strtoupper(Str::random(4).'-'.Str::random(4));
    }
}
