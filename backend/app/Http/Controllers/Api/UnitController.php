<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;

class UnitController extends Controller
{
    /**
     * Units are a small, mostly-static lookup list (kg, g, L, ml, pc...).
     * Read-only from the client for Phase 4 -- seeded once via
     * UnitsSeeder; a management UI isn't worth building until a real need
     * for custom units shows up.
     */
    public function index()
    {
        return response()->json([
            'data' => Unit::orderBy('name')->get()->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'abbreviation' => $u->abbreviation,
            ]),
        ]);
    }
}
