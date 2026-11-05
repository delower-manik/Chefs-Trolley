<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An internal announcement record -- see the migration's note. "Sending"
 * this only marks it sent in our own database; it is not itself a delivery
 * mechanism.
 */
class Notification extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Mirrors the column defaults in the migration. Without this, a model
     * returned straight from create() lacks those attributes (they only exist
     * in the database row), so API responses built from it would show null
     * until the record is re-read.
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected $fillable = ['branch_id', 'title', 'body', 'audience', 'customer_id', 'status', 'sent_at', 'created_by'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
