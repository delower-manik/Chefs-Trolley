<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;

class MarketingConsentController extends Controller
{
    /** GET /marketing/unsubscribe/{customer}?signature=… — idempotent, safe to open twice. */
    public function unsubscribe(Customer $customer)
    {
        if (! $customer->marketing_opt_out) {
            $customer->update(['marketing_opt_out' => true, 'marketing_opt_out_at' => now()]);
            AuditLog::record(actorId: null, actorName: 'Customer (email link)', action: 'customer.unsubscribed', subjectType: Customer::class, subjectId: $customer->id, branchId: $customer->branch_id);
        }

        return response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Unsubscribed</title>'
            .'<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem"><h1>You are unsubscribed</h1>'
            .'<p>We will no longer send you marketing messages. Messages about your own orders are not affected.</p></body>', 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
