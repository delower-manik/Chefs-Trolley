<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DeviceToken;
use Illuminate\Http\Request;

/** The customer app registers its push token here. A token belongs to exactly one owner, so a shared phone never gets the previous person's order alerts. */
class DeviceTokenController extends Controller
{
    /** POST /web/device-tokens {token, platform} */
    public function store(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:500'], 'platform' => ['required', 'in:android,ios,web']]);
        $device = DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            ['owner_type' => Customer::class, 'owner_id' => $request->attributes->get('customer')->id, 'platform' => $data['platform'], 'last_seen_at' => now()],
        );

        return response()->json(['data' => ['id' => $device->id, 'platform' => $device->platform]], 201);
    }

    /** DELETE /web/device-tokens {token} — only the owner's own token can be removed. */
    public function destroy(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:500']]);
        DeviceToken::where('token', $data['token'])->where('owner_type', Customer::class)->where('owner_id', $request->attributes->get('customer')->id)->delete();

        return response()->noContent();
    }
}
