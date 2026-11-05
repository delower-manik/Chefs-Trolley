<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_id', 'branch_id', 'gateway_key', 'amount', 'currency', 'status', 'provider_ref', 'provider_trx', 'redirect_url',
        'qr_payload', 'payload', 'payment_id', 'note', 'expires_at', 'settled_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'payload' => 'array', 'expires_at' => 'datetime', 'settled_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['created', 'pending'], true);
    }

    /** Token carried in the callback URL so only the provider we told can reach this session. Not a secret on its own: the callback never trusts what it receives. */
    public function callbackToken(): string
    {
        return substr(hash_hmac('sha256', 'gw-callback|'.$this->id, (string) config('app.key')), 0, 40);
    }
}
