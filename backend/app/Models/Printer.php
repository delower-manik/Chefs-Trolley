<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Printer extends Model
{
    use HasUuids;

    protected $attributes = ['port' => 9100, 'paper_width' => 80, 'role' => 'receipt', 'opens_drawer' => false, 'is_active' => true];

    protected $fillable = [
        'branch_id', 'name', 'connection', 'role', 'host', 'port', 'kitchen_station_id', 'paper_width',
        'opens_drawer', 'is_active',
    ];

    protected function casts(): array
    {
        return ['opens_drawer' => 'boolean', 'is_active' => 'boolean', 'port' => 'integer', 'paper_width' => 'integer'];
    }

    /** Characters per line for the paper width (Font A). */
    public function columns(): int
    {
        return $this->paper_width === 58 ? 32 : 48;
    }
}
