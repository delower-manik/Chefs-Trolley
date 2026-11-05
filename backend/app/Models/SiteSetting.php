<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Singleton-style: the app always works with the single earliest row.
 * `current()` creates it on first access so callers never have to check
 * for "settings don't exist yet" as a special case.
 */
class SiteSetting extends Model
{
    use HasUuids;

    /**
     * Mirrors the column defaults in the migration. Without this, a model
     * returned straight from create() lacks those attributes (they only exist
     * in the database row), so API responses built from it would show null
     * until the record is re-read.
     */
    protected $attributes = [
        'site_name' => 'Chefs Trolley',
        'primary_color' => '#D60000',
    ];

    protected $fillable = [
        'site_name', 'tagline', 'primary_color', 'logo_media_id', 'favicon_media_id',
        'contact_phone', 'contact_email', 'social_facebook', 'social_instagram',
    ];

    public function logoMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'logo_media_id');
    }

    public function faviconMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'favicon_media_id');
    }

    public static function current(): self
    {
        return static::query()->oldest('created_at')->first() ?? static::create([]);
    }
}
