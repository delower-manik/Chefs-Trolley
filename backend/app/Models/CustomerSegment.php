<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CustomerSegment extends Model
{
    use HasUuids;

    protected $fillable = ['branch_id', 'name', 'description', 'match', 'rules', 'created_by'];

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }
}
