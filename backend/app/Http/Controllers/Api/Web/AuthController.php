<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Services\Web\CustomerAuth;
use App\Support\BdPhone;
use Illuminate\Http\Request;

/** Customer website sign-in: POST /web/auth/code then POST /web/auth/verify. */
class AuthController extends Controller
{
    public function __construct(private CustomerAuth $auth) {}

    public function sendCode(Request $request)
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']]);
        $phone = BdPhone::normalize($data['phone']);
        if (! $phone) {
            return response()->json(['code' => 'invalid_phone', 'message' => 'Enter a valid Bangladeshi mobile number, like 01712345678.'], 422);
        }
        $result = $this->auth->sendCode($phone, (string) $request->ip());
        if ($result === 'cooldown') {
            return response()->json(['code' => 'code_cooldown', 'message' => 'A code was just sent. Please wait a minute before asking for another.'], 429);
        }
        if ($result === 'limited') {
            return response()->json(['code' => 'code_limit', 'message' => 'Too many code requests. Try again later.'], 429);
        }

        // Same answer whether or not this number already has an account.
        return response()->json(['data' => ['sent' => true, 'validMinutes' => CustomerAuth::TTL_MINUTES]]);
    }

    public function verify(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'code' => ['required', 'digits:6'],
            'name' => ['nullable', 'string', 'min:2', 'max:80'],
        ]);
        $phone = BdPhone::normalize($data['phone']);
        if (! $phone) {
            return response()->json(['code' => 'invalid_phone', 'message' => 'Enter a valid Bangladeshi mobile number.'], 422);
        }

        $check = $this->auth->checkCode($phone, $data['code']);
        if ($check !== 'ok') {
            return response()->json([
                'code' => $check === 'expired' ? 'code_expired' : 'code_invalid',
                'message' => $check === 'expired' ? 'This code has expired. Ask for a new one.' : 'That code is not right.',
            ], 422);
        }

        $customer = $this->auth->findCustomer($phone);
        if (! $customer) {
            // The code is proven, so it is safe to reveal that this is a first visit. The code stays valid for the retry.
            if (empty($data['name'])) {
                return response()->json(['code' => 'name_required', 'message' => 'Welcome! Tell us your name to finish.'], 422);
            }
            $customer = Customer::create(['name' => trim($data['name']), 'phone' => $phone, 'is_active' => true]);
        } elseif (! $customer->is_active) {
            return response()->json(['code' => 'account_disabled', 'message' => 'This account is not active. Please contact the restaurant.'], 403);
        }
        $this->auth->consume($phone);

        [$customer, $token] = $this->auth->startSession($customer, (string) $request->ip(), $request->userAgent());
        AuditLog::record(actorId: null, actorName: 'customer:'.$customer->phone, action: 'customer.signed_in', subjectType: Customer::class, subjectId: $customer->id, ipAddress: $request->ip());

        return response()->json(['data' => ['token' => $token, 'expiresInDays' => CustomerAuth::SESSION_DAYS, 'customer' => $this->customer($customer)]]);
    }

    public function me(Request $request)
    {
        return response()->json(['data' => $this->customer($request->attributes->get('customer'))]);
    }

    public function update(Request $request)
    {
        $c = $request->attributes->get('customer');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:80'],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', 'in:en,bn'],
        ]);
        $c->update($data);

        return response()->json(['data' => $this->customer($c->fresh())]);
    }

    public function logout(Request $request)
    {
        $request->attributes->get('customer_session')->delete();

        return response()->json(['data' => ['signedOut' => true]]);
    }

    private function customer(Customer $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email, 'address' => $c->address,
            'locale' => $c->locale, 'loyaltyPoints' => (int) $c->loyalty_points,
        ];
    }
}
