<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $fillable = ['name', 'email', 'password', 'active_branch_id', 'is_active'];

    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes'];

    /**
     * Mirrors the column default (users.is_active DEFAULT true). Without it a
     * freshly created model has no `is_active` attribute until it is reloaded,
     * and `! $user->is_active` would treat a brand-new, active account as
     * disabled.
     */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted',
            'mfa_confirmed_at' => 'datetime',
            'locked_until' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_user');
    }

    public function activeBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'active_branch_id');
    }

    /** Flattened, deduplicated permission keys across all assigned roles. */
    public function permissionKeys(): array
    {
        return $this->roles()
            ->with('permissions:id,name')
            ->get()
            ->pluck('permissions')
            ->flatten()
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    public function hasPermissionKey(string $permission): bool
    {
        if ($this->roles()->where('name', 'Super Admin')->exists()) {
            return true;
        }

        return in_array($permission, $this->permissionKeys(), true);
    }
}
