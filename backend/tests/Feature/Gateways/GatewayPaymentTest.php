<?php

namespace Tests\Feature\Gateways;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentGatewayConfig;
use App\Models\PaymentSession;
use App\Services\Gateways\Drivers\BanglaQr;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\TakesPayments;
use Tests\TestCase;

class GatewayPaymentTest extends TestCase
{
    use TakesPayments;

    /** Http::fake() never overrides earlier stubs, so each call starts from a fresh factory. */
    private function fakeHttp(array $stubs): void
    {
        Http::swap(new Factory);
        Http::fake($stubs);
    }

    private function enable(string $key, array $config): void
    {
        PaymentGatewayConfig::updateOrCreate(['gateway_key' => $key], ['display_name' => $key, 'is_enabled' => true, 'config' => $config]);
    }

    /** @return array{0: Order, 1: PaymentSession} an order of 200 with a started online payment */
    private function start(string $key, ?float $amount = null): array
    {
        [, $order] = $this->cashierWithOrder(['integrations.manage']);
        $body = ['gateway' => $key] + ($amount ? ['amount' => $amount] : []);
        $id = $this->postJson("/api/v1/orders/{$order->id}/gateway-payments", $body)->assertStatus(201)->json('data.id');

        return [$order, PaymentSession::findOrFail($id)];
    }

    private function hit(PaymentSession $s, string $query = '', string $suffix = ''): TestResponse
    {
        return $this->get("/api/v1/payment-callbacks/{$s->gateway_key}/{$s->id}/{$s->callbackToken()}{$suffix}{$query}");
    }

    private function setUpAamar(): void
    {
        $this->enable('aamarpay', ['store_id' => 'aamarpaytest', 'signature_key' => 'sig', 'sandbox' => '1']);
        $this->fakeHttp(['sandbox.aamarpay.com/jsonpost.php' => Http::response(['result' => 'true', 'payment_url' => 'https://sandbox.aamarpay.com/pay/abc'])]);
    }

    // ---- the shared rules: amount fixed server-side, credited once, only after the provider confirms ----

    public function test_a_payment_is_credited_only_after_the_provider_confirms_and_only_once(): void
    {
        $this->setUpAamar();
        [$order, $s] = $this->start('aamarpay');

        $this->assertSame('pending', $s->status);
        $this->assertSame('200.00', $s->amount);
        $this->assertSame('https://sandbox.aamarpay.com/pay/abc', $s->redirect_url);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'jsonpost.php') && $r['tran_id'] === $s->id && $r['amount'] === '200.00' && $r['currency'] === 'BDT');

        // The customer's browser lands back claiming success — but the provider says it's still pending.
        $this->fakeHttp(['sandbox.aamarpay.com/api/v1/trxcheck/*' => Http::response(['pay_status' => 'Pending'])]);
        $this->hit($s, '?pay_status=Successful&amount=200')->assertStatus(303);
        $this->assertSame(0, Payment::count());
        $this->assertSame('unpaid', $order->fresh()->payment_state);

        $this->fakeHttp(['sandbox.aamarpay.com/api/v1/trxcheck/*' => Http::response(['pay_status' => 'Successful', 'pg_txnid' => 'PG123', 'amount' => '200.00', 'currency' => 'BDT'])]);
        $this->hit($s)->assertRedirect()->assertRedirectContains('/payment-result?session='.$s->id.'&status=paid');
        $this->hit($s, '', '/ipn')->assertOk()->assertJsonPath('status', 'paid'); // second notification: no double credit

        $payment = Payment::firstOrFail();
        $this->assertSame(1, Payment::count());
        $this->assertSame('online', $payment->method);
        $this->assertSame('aamarpay:PG123', $payment->reference);
        $this->assertSame('200.00', $payment->amount);
        $this->assertSame('paid', $order->fresh()->payment_state);
        $this->assertSame('paid', $s->fresh()->status);
        $this->assertSame($payment->id, $s->fresh()->payment_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'gateway_payment.settled']);
    }

    public function test_the_callback_needs_the_right_token_and_nothing_in_it_is_trusted(): void
    {
        $this->setUpAamar();
        [, $s] = $this->start('aamarpay');

        $this->get("/api/v1/payment-callbacks/aamarpay/{$s->id}/wrongtoken")->assertStatus(404);
        $this->get("/api/v1/payment-callbacks/sslcommerz/{$s->id}/{$s->callbackToken()}")->assertStatus(404);
        $this->assertSame(0, Payment::count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'trxcheck'));
    }

    public function test_an_amount_that_differs_from_what_we_asked_for_is_held_for_review_not_credited(): void
    {
        $this->setUpAamar();
        [$order, $s] = $this->start('aamarpay');

        $this->fakeHttp(['sandbox.aamarpay.com/api/v1/trxcheck/*' => Http::response(['pay_status' => 'Successful', 'pg_txnid' => 'PG9', 'amount' => '20.00', 'currency' => 'BDT'])]);
        $this->hit($s)->assertRedirect();

        $this->assertSame('review', $s->fresh()->status);
        $this->assertStringContainsString('20.00', $s->fresh()->note);
        $this->assertSame(0, Payment::count());
        $this->assertSame('unpaid', $order->fresh()->payment_state);
        $this->assertDatabaseHas('audit_logs', ['action' => 'gateway_payment.needs_review']);
    }

    public function test_money_arriving_after_the_session_was_closed_is_flagged_not_silently_credited(): void
    {
        $this->setUpAamar();
        [, $s] = $this->start('aamarpay');
        $this->postJson("/api/v1/gateway-payments/{$s->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->fakeHttp(['sandbox.aamarpay.com/api/v1/trxcheck/*' => Http::response(['pay_status' => 'Successful', 'pg_txnid' => 'LATE1', 'amount' => '200.00', 'currency' => 'BDT'])]);
        $this->hit($s)->assertRedirect();

        $this->assertSame('review', $s->fresh()->status);
        $this->assertSame(0, Payment::count());
        $this->assertSame('LATE1', $s->fresh()->provider_trx);
    }

    public function test_it_cannot_be_started_for_a_disabled_gateway_a_paid_order_or_too_much(): void
    {
        [, $order] = $this->cashierWithOrder(['integrations.manage']);

        $this->postJson("/api/v1/orders/{$order->id}/gateway-payments", ['gateway' => 'bkash'])->assertStatus(422)->assertJsonPath('code', 'gateway_unavailable');
        $this->postJson("/api/v1/orders/{$order->id}/gateway-payments", ['gateway' => 'visa'])->assertStatus(422);

        $this->setUpAamar();
        $this->postJson("/api/v1/orders/{$order->id}/gateway-payments", ['gateway' => 'aamarpay', 'amount' => 250])->assertStatus(422);
        $this->postJson("/api/v1/orders/{$order->id}/gateway-payments", ['gateway' => 'aamarpay', 'amount' => 80])->assertStatus(201)->assertJsonPath('data.amount', 80);

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'card', 'amount' => 200])->assertStatus(201);
        $this->postJson("/api/v1/orders/{$order->id}/gateway-payments", ['gateway' => 'aamarpay'])->assertStatus(422)->assertJsonPath('code', 'gateway_unavailable');
    }

    public function test_a_provider_error_at_start_fails_cleanly_and_an_open_session_is_reused(): void
    {
        $this->enable('aamarpay', ['store_id' => 's', 'signature_key' => 'k', 'sandbox' => '1']);
        $this->fakeHttp(['*' => Http::response(['result' => 'false', 'reason' => 'Invalid store'], 200)]);
        [, $order] = $this->cashierWithOrder();
        $this->postJson("/api/v1/orders/{$order->id}/gateway-payments", ['gateway' => 'aamarpay'])
            ->assertStatus(422)->assertJsonPath('code', 'gateway_unavailable');
        $this->assertSame('failed', PaymentSession::firstOrFail()->status);

        $this->setUpAamar();
        $a = $this->postJson("/api/v1/orders/{$order->id}/gateway-payments", ['gateway' => 'aamarpay'])->assertStatus(201)->json('data.id');
        $b = $this->postJson("/api/v1/orders/{$order->id}/gateway-payments", ['gateway' => 'aamarpay'])->assertStatus(201)->json('data.id');
        $this->assertSame($a, $b);
    }

    public function test_paying_another_way_meanwhile_is_flagged_as_an_overpayment(): void
    {
        $this->setUpAamar();
        [$order, $s] = $this->start('aamarpay');
        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'card'])->assertStatus(201);

        $this->fakeHttp(['sandbox.aamarpay.com/api/v1/trxcheck/*' => Http::response(['pay_status' => 'Successful', 'pg_txnid' => 'PG5', 'amount' => '200.00', 'currency' => 'BDT'])]);
        $this->hit($s)->assertRedirect();

        $this->assertSame('paid', $s->fresh()->status);
        $this->assertStringContainsString('overpaid by 200.00', $s->fresh()->note);
    }

    public function test_sessions_are_branch_scoped_and_need_pos_access(): void
    {
        $this->setUpAamar();
        [, $s] = $this->start('aamarpay');

        $this->actingAsUserWithPermissions(['pos.access', 'orders.view'], $this->createBranch());
        $this->getJson("/api/v1/gateway-payments/{$s->id}")->assertStatus(404);

        $this->actingAsUserWithPermissions(['orders.view'], Branch::first());
        $this->getJson("/api/v1/gateway-payments/{$s->id}")->assertStatus(403);
    }

    public function test_reconcile_settles_missed_callbacks_and_expires_old_sessions(): void
    {
        $this->setUpAamar();
        [$order, $paid] = $this->start('aamarpay');
        $this->fakeHttp(['sandbox.aamarpay.com/api/v1/trxcheck/*' => Http::response(['pay_status' => 'Successful', 'pg_txnid' => 'PG77', 'amount' => '200.00', 'currency' => 'BDT'])]);
        $this->artisan('gateways:reconcile')->assertSuccessful();
        $this->assertSame('paid', $paid->fresh()->status);

        $stale = PaymentSession::create(['order_id' => $order->id, 'branch_id' => $order->branch_id, 'gateway_key' => 'aamarpay', 'amount' => 50, 'status' => 'pending', 'provider_ref' => 'x', 'expires_at' => now()->subMinute()]);
        $this->fakeHttp(['sandbox.aamarpay.com/api/v1/trxcheck/*' => Http::response(['pay_status' => 'Pending'])]);
        $this->artisan('gateways:reconcile')->assertSuccessful();
        $this->assertSame('expired', $stale->fresh()->status);
    }

    // ---- per driver: the request each one makes and how it reads the answer ----

    public function test_sslcommerz(): void
    {
        $this->enable('sslcommerz', ['store_id' => 'st', 'store_password' => 'pw', 'sandbox' => '1']);
        $this->fakeHttp(['sandbox.sslcommerz.com/gwprocess/v4/api.php' => Http::response(['status' => 'SUCCESS', 'sessionkey' => 'K1', 'GatewayPageURL' => 'https://sandbox.sslcommerz.com/gw/K1'])]);
        [$order, $s] = $this->start('sslcommerz');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'gwprocess') && $r['tran_id'] === $s->id && $r['total_amount'] === '200.00' && $r['store_passwd'] === 'pw'
            && str_ends_with($r['ipn_url'], '/ipn') && str_contains($r['success_url'], $s->callbackToken()));
        $this->assertSame('https://sandbox.sslcommerz.com/gw/K1', $s->redirect_url);

        $this->fakeHttp(['sandbox.sslcommerz.com/validator/api/merchantTransIDvalidationAPI.php*' => Http::response(['APIConnect' => 'DONE', 'no_of_trans_found' => 1,
            'element' => [['status' => 'VALID', 'tran_id' => $s->id, 'bank_tran_id' => 'BANK1', 'amount' => '200.00', 'currency_type' => 'BDT']]])]);
        $this->hit($s, '?status=VALID', '/ipn')->assertOk();
        $this->assertSame('sslcommerz:BANK1', Payment::firstOrFail()->reference);
        $this->assertSame('paid', $order->fresh()->payment_state);
    }

    public function test_sslcommerz_failed_and_unfound_transactions(): void
    {
        $this->enable('sslcommerz', ['store_id' => 'st', 'store_password' => 'pw', 'sandbox' => '1']);
        $this->fakeHttp(['sandbox.sslcommerz.com/gwprocess/v4/api.php' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://x.test/p'])]);
        [, $s] = $this->start('sslcommerz');

        $this->fakeHttp(['sandbox.sslcommerz.com/validator/*' => Http::response(['no_of_trans_found' => 0, 'element' => []])]);
        $this->hit($s)->assertRedirect();
        $this->assertSame('pending', $s->fresh()->status);

        $this->fakeHttp(['sandbox.sslcommerz.com/validator/*' => Http::response(['element' => [['status' => 'FAILED']]])]);
        $this->hit($s)->assertRedirect();
        $this->assertSame('failed', $s->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_bkash_creates_then_executes_after_the_customer_approves(): void
    {
        $this->enable('bkash', ['app_key' => 'AK', 'app_secret' => 'AS', 'username' => 'u', 'password' => 'p', 'sandbox' => '1']);
        $this->fakeHttp([
            '*/token/grant' => Http::response(['id_token' => 'TOK', 'statusCode' => '0000']),
            '*/create' => Http::response(['statusCode' => '0000', 'paymentID' => 'TR0011abc', 'bkashURL' => 'https://sandbox.payment.bkash.com/?paymentId=TR0011abc']),
        ]);
        [$order, $s] = $this->start('bkash');

        $this->assertSame('TR0011abc', $s->provider_ref);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/create') && $r['amount'] === '200.00' && $r['currency'] === 'BDT' && $r['intent'] === 'sale'
            && str_contains($r['callbackURL'], $s->callbackToken()) && $r->header('Authorization')[0] === 'TOK' && $r->header('X-APP-Key')[0] === 'AK');

        // Back from bKash with status=success: still only "Initiated" until we execute — and the id in the URL is ignored.
        $this->fakeHttp([
            '*/token/grant' => Http::response(['id_token' => 'TOK']),
            '*/payment/status' => Http::response(['transactionStatus' => 'Initiated']),
            '*/execute' => Http::response(['statusCode' => '0000', 'transactionStatus' => 'Completed', 'trxID' => 'BK8X', 'amount' => '200', 'currency' => 'BDT']),
        ]);
        $this->hit($s, '?paymentID=EVIL999&status=success')->assertRedirect();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/execute') && $r['paymentID'] === 'TR0011abc');
        $this->assertSame('bkash:BK8X', Payment::firstOrFail()->reference);
        $this->assertSame('paid', $order->fresh()->payment_state);
    }

    public function test_bkash_cancel_and_already_completed(): void
    {
        $this->enable('bkash', ['app_key' => 'AK', 'app_secret' => 'AS', 'username' => 'u', 'password' => 'p']);
        $this->fakeHttp(['*/token/grant' => Http::response(['id_token' => 'TOK']), '*/create' => Http::response(['statusCode' => '0000', 'paymentID' => 'P1', 'bkashURL' => 'https://pay.test/1'])]);
        [, $s] = $this->start('bkash');

        $this->fakeHttp(['*/token/grant' => Http::response(['id_token' => 'TOK']), '*/payment/status' => Http::response(['transactionStatus' => 'Initiated'])]);
        $this->hit($s, '?status=cancel')->assertRedirect();
        $this->assertSame('failed', $s->fresh()->status);
        $this->assertStringContainsString('cancelled', $s->fresh()->note);

        // The customer did pay after all; a later check finds it Completed — surfaced for review, not auto-credited.
        $this->fakeHttp(['*/token/grant' => Http::response(['id_token' => 'TOK']), '*/payment/status' => Http::response(['transactionStatus' => 'Completed', 'trxID' => 'BKLATE', 'amount' => '200.00'])]);
        $this->postJson("/api/v1/gateway-payments/{$s->id}/check")->assertOk();
        $this->assertSame('review', $s->fresh()->status);
    }

    public function test_nagad_encrypts_signs_and_verifies(): void
    {
        $gen = fn () => openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $merchant = $gen();
        $pg = $gen();
        openssl_pkey_export($merchant, $merchantPriv);
        $merchantPub = openssl_pkey_get_details($merchant)['key'];
        openssl_pkey_export($pg, $pgPriv);
        $pgPub = openssl_pkey_get_details($pg)['key'];

        $this->enable('nagad', ['merchant_id' => '683002007104225', 'merchant_number' => '01711111111', 'merchant_private_key' => $merchantPriv, 'pg_public_key' => $pgPub, 'sandbox' => '1']);

        $reply = function (array $data) use ($merchantPub) {
            openssl_public_encrypt(json_encode($data), $enc, openssl_pkey_get_public($merchantPub), OPENSSL_PKCS1_PADDING);

            return base64_encode($enc);
        };
        $this->fakeHttp([
            '*/check-out/initialize/*' => Http::response(['sensitiveData' => $reply(['paymentReferenceId' => 'REF123', 'challenge' => 'CH1']), 'signature' => 'x']),
            '*/check-out/complete/REF123' => Http::response(['status' => 'Success', 'callBackUrl' => 'https://sandbox-ssl.mynagad.com/pay/REF123']),
        ]);
        [$order, $s] = $this->start('nagad');

        $this->assertSame('REF123', $s->provider_ref);
        $this->assertSame('https://sandbox-ssl.mynagad.com/pay/REF123', $s->redirect_url);
        // What we sent to Nagad can only be read with Nagad's private key, and is signed by ours.
        Http::assertSent(function ($r) use ($pg, $merchantPub) {
            if (! str_contains($r->url(), '/initialize/')) {
                return false;
            }
            openssl_private_decrypt(base64_decode($r['sensitiveData']), $plain, $pg, OPENSSL_PKCS1_PADDING);
            $data = json_decode($plain, true);

            return $data['merchantId'] === '683002007104225' && strlen($data['challenge']) === 40
                && openssl_verify($plain, base64_decode($r['signature']), openssl_pkey_get_public($merchantPub), OPENSSL_ALGO_SHA256) === 1;
        });
        Http::assertSent(function ($r) use ($pg) {
            if (! str_contains($r->url(), '/complete/')) {
                return false;
            }
            openssl_private_decrypt(base64_decode($r['sensitiveData']), $plain, $pg, OPENSSL_PKCS1_PADDING);
            $data = json_decode($plain, true);

            return $data['amount'] === '200.00' && $data['currencyCode'] === '050' && $data['challenge'] === 'CH1';
        });

        $this->fakeHttp(['*/verify/payment/REF123' => Http::response(['status' => 'Success', 'amount' => '200.00', 'issuerPaymentRefNo' => 'ISS77'])]);
        $this->hit($s, '?status=Success&payment_ref_id=OTHER')->assertRedirect();
        $this->assertSame('nagad:ISS77', Payment::firstOrFail()->reference);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/verify/payment/REF123'));
    }

    public function test_eps(): void
    {
        $this->enable('eps', ['username' => 'u', 'password' => 'p', 'hash_key' => 'HK', 'merchant_id' => 'M1', 'store_id' => 'S1', 'sandbox' => '1']);
        $this->fakeHttp([
            '*/v1/Auth/GetToken' => Http::response(['token' => 'JWT']),
            '*/v1/EPSEngine/InitializeEPS' => Http::response(['TransactionId' => 'E1', 'RedirectURL' => 'https://sandboxpg.eps.com.bd/pay/E1']),
        ]);
        [$order, $s] = $this->start('eps');

        $expectedHash = base64_encode(hash_hmac('sha512', $s->id, 'HK', true));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'InitializeEPS') && $r['merchantTransactionId'] === $s->id && (float) $r['totalAmount'] === 200.0
            && $r->header('x-hash')[0] === $expectedHash && $r->header('Authorization')[0] === 'Bearer JWT');

        $this->fakeHttp(['*/v1/Auth/GetToken' => Http::response(['token' => 'JWT']), '*/CheckMerchantTransactionStatus*' => Http::response(['Status' => 'Success', 'TotalAmount' => 200, 'EpsTransactionId' => 'EP9'])]);
        $this->hit($s)->assertRedirect();
        $this->assertSame('eps:EP9', Payment::firstOrFail()->reference);
        $this->assertSame('paid', $order->fresh()->payment_state);
    }

    public function test_piprapay(): void
    {
        $this->enable('piprapay', ['base_url' => 'https://pay.myshop.test/', 'api_key' => 'PK']);
        $this->fakeHttp(['pay.myshop.test/api/create-charge' => Http::response(['status' => true, 'pp_id' => 'PP1', 'pp_url' => 'https://pay.myshop.test/payment/PP1'])]);
        [$order, $s] = $this->start('piprapay');

        Http::assertSent(fn ($r) => $r->header('mh-piprapay-api-key')[0] === 'PK' && $r['amount'] === '200.00' && $r['currency'] === 'BDT' && str_contains($r['redirect_url'], $s->callbackToken()));
        $this->assertSame('PP1', $s->provider_ref);

        $this->fakeHttp(['pay.myshop.test/api/verify-payments' => Http::response(['status' => 'completed', 'amount' => '200.00', 'transaction_id' => 'T55', 'currency' => 'BDT'])]);
        $this->hit($s, '?pp_id=SOMEONE-ELSE')->assertRedirect();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'verify-payments') && $r['pp_id'] === 'PP1');
        $this->assertSame('piprapay:T55', Payment::firstOrFail()->reference);
    }

    // ---- Bangla QR: a payload generator + a person confirming ----

    public function test_emv_crc_matches_the_standard_test_vector(): void
    {
        $this->assertSame('29B1', BanglaQr::crc16('123456789'));
    }

    public function test_bangla_qr_builds_a_valid_payload_and_is_confirmed_by_hand(): void
    {
        $this->enable('bangla_qr', ['merchant_name' => 'Chefs Trolley', 'merchant_city' => 'Dhaka', 'account_template' => '26200016com.bank.example01061234565204']);
        // (the template above is deliberately sloppy EMV; the driver only checks it is tag 26–51 shaped)
        [$order, $s] = $this->start('bangla_qr', 150);

        $qr = $s->qr_payload;
        $this->assertStringStartsWith('000201010212', $qr);
        $this->assertStringContainsString('5406150.00', $qr);
        $this->assertStringContainsString('5303050', $qr);
        $this->assertStringContainsString('5802BD', $qr);
        $this->assertStringContainsString('5913Chefs Trolley', $qr);
        $this->assertStringContainsString('6005Dhaka', $qr);
        $this->assertMatchesRegularExpression('/6304[0-9A-F]{4}$/', $qr);
        $this->assertSame(BanglaQr::crc16(substr($qr, 0, -4)), substr($qr, -4)); // checksum covers everything before itself

        // No provider to ask: "check now" changes nothing, and only a person can confirm.
        $this->postJson("/api/v1/gateway-payments/{$s->id}/check")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson("/api/v1/gateway-payments/{$s->id}/confirm", ['transaction_id' => 'ab'])->assertStatus(422);
        $this->postJson("/api/v1/gateway-payments/{$s->id}/confirm", ['transaction_id' => 'BANK-TRX-1'])->assertOk()->assertJsonPath('data.status', 'paid');
        $this->postJson("/api/v1/gateway-payments/{$s->id}/confirm", ['transaction_id' => 'BANK-TRX-1'])->assertStatus(422)->assertJsonPath('code', 'session_closed');

        $this->assertSame(1, Payment::count());
        $this->assertSame('bangla_qr:BANK-TRX-1', Payment::firstOrFail()->reference);
        $this->assertSame('150.00', Payment::firstOrFail()->amount);
        $this->assertSame('unpaid', $order->fresh()->payment_state); // 150 of 200
        $this->assertStringContainsString('manually confirmed', json_encode(AuditLog::where('action', 'gateway_payment.settled')->firstOrFail()->changes));
    }

    public function test_manual_confirm_is_refused_for_automatic_gateways(): void
    {
        $this->setUpAamar();
        [, $s] = $this->start('aamarpay');
        $this->postJson("/api/v1/gateway-payments/{$s->id}/confirm", ['transaction_id' => 'FAKE-TRX'])->assertStatus(422)->assertJsonPath('code', 'not_manual');
        $this->assertSame(0, Payment::count());
    }

    public function test_a_malformed_qr_template_is_rejected_before_anything_is_shown(): void
    {
        $this->enable('bangla_qr', ['merchant_name' => 'X', 'merchant_city' => 'Y', 'account_template' => 'not emv']);
        [, $order] = $this->cashierWithOrder();
        $this->postJson("/api/v1/orders/{$order->id}/gateway-payments", ['gateway' => 'bangla_qr'])->assertStatus(422);
    }

    // ---- settings ----

    public function test_settings_list_every_gateway_with_fields_and_never_reveal_secrets(): void
    {
        $this->actingAsUserWithPermissions(['integrations.manage', 'pos.access']);
        $rows = collect($this->getJson('/api/v1/payment-gateways')->assertOk()->json('data'));

        foreach (['bkash', 'nagad', 'sslcommerz', 'eps', 'aamarpay', 'bangla_qr', 'piprapay'] as $key) {
            $this->assertTrue((bool) $rows->firstWhere('gatewayKey', $key)['online'], $key);
        }

        $bkash = PaymentGatewayConfig::where('gateway_key', 'bkash')->firstOrFail();
        $this->putJson("/api/v1/payment-gateways/{$bkash->id}", ['config' => ['app_key' => 'AK1', 'app_secret' => 'TOP-SECRET', 'username' => 'u', 'password' => 'pw', 'evil' => 'x']])
            ->assertOk()->assertJsonMissing(['TOP-SECRET'])->assertJsonMissing(['pw']);

        $row = collect($this->getJson('/api/v1/payment-gateways')->json('data'))->firstWhere('gatewayKey', 'bkash');
        $fields = collect($row['fields'])->keyBy('key');
        $this->assertSame('AK1', $fields['app_key']['value']);
        $this->assertNull($fields['app_secret']['value']);
        $this->assertTrue($fields['app_secret']['isSet']);
        $this->assertFalse($fields->has('evil'));

        // Saving the form again with blanks keeps the stored secret; a new value replaces it.
        $this->putJson("/api/v1/payment-gateways/{$bkash->id}", ['config' => ['app_secret' => '', 'username' => 'u2']])->assertOk();
        $this->assertSame('TOP-SECRET', $bkash->fresh()->config['app_secret']);
        $this->assertSame('u2', $bkash->fresh()->config['username']);
    }

    public function test_a_gateway_cannot_be_enabled_without_its_required_credentials(): void
    {
        $this->actingAsUserWithPermissions(['integrations.manage']);
        $this->getJson('/api/v1/payment-gateways');
        $g = PaymentGatewayConfig::where('gateway_key', 'sslcommerz')->firstOrFail();

        $this->putJson("/api/v1/payment-gateways/{$g->id}", ['is_enabled' => true])->assertStatus(422)->assertJsonPath('code', 'credentials_missing');
        $this->putJson("/api/v1/payment-gateways/{$g->id}", ['is_enabled' => true, 'config' => ['store_id' => 'a', 'store_password' => 'b']])->assertOk()->assertJsonPath('data.isEnabled', true);
    }

    public function test_options_lists_only_usable_methods(): void
    {
        $this->actingAsUserWithPermissions(['pos.access', 'integrations.manage']);
        $this->setUpAamar();
        PaymentGatewayConfig::updateOrCreate(['gateway_key' => 'eps'], ['display_name' => 'EPS', 'is_enabled' => true, 'config' => ['username' => 'u']]); // incomplete
        $this->assertSame(['aamarpay'], collect($this->getJson('/api/v1/gateway-payments/options')->json('data'))->pluck('key')->all());
    }
}
