<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasUuids;

    public $timestamps = false; // only created_at is tracked, set explicitly

    protected $fillable = [
        'actor_id', 'actor_name', 'action', 'subject_type', 'subject_id',
        'branch_id', 'changes', 'ip_address', 'created_at',
    ];

    /**
     * The audit trail is append-only: no code path may rewrite or remove a
     * row. This guards the application layer only -- production should also
     * revoke UPDATE/DELETE on this table for the app's database user (see
     * README, security gaps).
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('Audit logs are append-only and cannot be modified.');
        });
        static::deleting(function () {
            throw new \LogicException('Audit logs are append-only and cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Central write path for every audited action (Non-Negotiable Rule #10:
     * destructive actions must be explicit and auditable). Never write to
     * this table directly from a controller -- always go through here.
     */
    public static function record(
        ?string $actorId,
        ?string $actorName,
        string $action,
        string $subjectType,
        string $subjectId,
        ?string $branchId = null,
        ?array $changes = null,
        ?string $ipAddress = null,
    ): self {
        return self::create([
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'branch_id' => $branchId,
            'changes' => $changes,
            'ip_address' => $ipAddress,
            'created_at' => now(),
        ]);
    }
}
