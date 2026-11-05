<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BackupRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'encrypted' => 'boolean', 'includes_media' => 'boolean', 'offsite_ok' => 'boolean', 'size_bytes' => 'integer',
            'started_at' => 'datetime', 'finished_at' => 'datetime', 'verified_at' => 'datetime',
        ];
    }
}
