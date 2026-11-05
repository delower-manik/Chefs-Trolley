<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Http\Request;

/** A staff app registers its FCM token here so it can receive push. A token belongs to exactly one owner. */
class DeviceTokenController extends Controller
{
    /** POST /device-tokens {token, platform} */
    public function store(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:500'],
            'platform' => ['required', 'in:android,ios,web'],
        ]);

        // Re-registering moves the token to the current user (a shared device changes hands).
        $device = DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            ['owner_type' => User::class, 'owner_id' => $request->user()->id, 'platform' => $data['platform'], 'last_seen_at' => now()],
        );

        return response()->json(['data' => ['id' => $device->id, 'platform' => $device->platform]], 201);
    }

    /** DELETE /device-tokens {token} — call on logout so the next user of the device isn't pushed this user's alerts. */
    public function destroy(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:500']]);
        DeviceToken::where('token', $data['token'])->where('owner_type', User::class)->where('owner_id', $request->user()->id)->delete();

        return response()->noContent();
    }
}
