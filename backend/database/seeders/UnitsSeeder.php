<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitsSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['name' => 'Kilogram', 'abbreviation' => 'kg'],
            ['name' => 'Gram', 'abbreviation' => 'g'],
            ['name' => 'Litre', 'abbreviation' => 'L'],
            ['name' => 'Millilitre', 'abbreviation' => 'ml'],
            ['name' => 'Piece', 'abbreviation' => 'pc'],
            ['name' => 'Dozen', 'abbreviation' => 'dz'],
            ['name' => 'Packet', 'abbreviation' => 'pkt'],
        ];

        foreach ($units as $unit) {
            Unit::firstOrCreate(['abbreviation' => $unit['abbreviation']], $unit);
        }
    }
}
