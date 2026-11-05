<?php

namespace Tests\Feature\Web;

use App\Models\Branch;
use App\Models\CmsPage;
use App\Models\Combo;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\FoodItem;
use App\Models\LoyaltyLedgerEntry;
use App\Models\Media;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\PaymentGatewayConfig;
use App\Models\PaymentSession;
use App\Models\Reservation;
use App\Models\Review;
use App\Services\AppSettings;
use App\Services\Realtime\Publisher;
use App\Support\BdPhone;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMenu;
use Tests\TestCase;

class CustomerWebsiteTest extends TestCase
{
    use BuildsMenu;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Gulshan', 'code' => 'GUL', 'address' => 'Road 1', 'phone' => '0171', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        config(['notify.sms.driver' => 'greenweb', 'notify.sms.greenweb.token' => 't']);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('Ok')]);
    }

    private function openOrdering(array $extra = []): void
    {
        app(AppSettings::class)->set(array_merge(['web.ordering' => true], $extra), null);
    }

    private function lastCode(string $phone): string
    {
        $row = NotificationDelivery::where('recipient', $phone)->where('purpose', 'otp')->latest()->firstOrFail();
        preg_match('/\b(\d{6})\b|(\d{6})/u', $row->body, $m);

        return $m[1] ?: $m[2];
    }

    /** Full sign-in; returns the bearer token. */
    private function signIn(string $phone = '01712345678', string $name = 'Ayesha'): string
    {
        $this->postJson('/api/v1/web/auth/code', ['phone' => $phone])->assertOk();
        $local = BdPhone::normalize($phone);
        $code = $this->lastCode($local);
        $res = $this->postJson('/api/v1/web/auth/verify', ['phone' => $phone, 'code' => $code, 'name' => $name])->assertOk();
        Cache::forget('otp:cooldown:'.$local);

        return $res->json('data.token');
    }

    private function as(string $token): self
    {
        $this->flushHeaders();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    // ---------- public catalog ----------

    public function test_public_menu_shows_only_available_items_and_no_internals(): void
    {
        $shown = $this->makeFoodItem($this->branch->id, 250, ['name' => 'Kacchi', 'is_available' => true]);
        $this->makeFoodItem($this->branch->id, 100, ['name' => 'Sold Out', 'is_available' => false]);
        $other = Branch::create(['name' => 'Dhanmondi', 'code' => 'DHA', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        $this->makeFoodItem($other->id, 90, ['name' => 'Other Branch Dish']);

        $res = $this->getJson("/api/v1/web/branches/{$this->branch->id}/menu")->assertOk();
        $names = collect($res->json('data.categories'))->flatMap(fn ($c) => collect($c['items'])->pluck('name'))->all();
        $this->assertSame(['Kacchi'], $names);
        $this->assertSame(250, (int) $res->json('data.categories.0.items.0.price'));
        $this->assertStringNotContainsString('recipe', strtolower($res->getContent()));
        $this->assertStringNotContainsString('cost', strtolower($res->getContent()));
        $this->assertStringNotContainsString($shown->kitchen_station_id ?? 'zzzz-none', $res->getContent());
        $this->getJson('/api/v1/web/branches/'.Str::uuid().'/menu')->assertStatus(404);
    }

    public function test_only_published_pages_are_public_and_site_info_reports_ordering_state(): void
    {
        CmsPage::create(['slug' => 'about', 'title' => 'About', 'sections' => [['type' => 'text', 'body' => 'Hi'], ['type' => 'script', 'body' => '<script>x</script>']], 'is_published' => true]);
        CmsPage::create(['slug' => 'draft', 'title' => 'Draft', 'is_published' => false]);
        $res = $this->getJson('/api/v1/web/pages/about')->assertOk();
        $this->assertCount(1, $res->json('data.sections')); // an unknown block type never reaches the site
        $this->getJson('/api/v1/web/pages/draft')->assertStatus(404);
        $this->getJson('/api/v1/web/site')->assertOk()->assertJsonPath('data.ordering.enabled', false);
        $this->assertSame(['about'], collect($this->getJson('/api/v1/web/sitemap')->json('data.pages'))->pluck('slug')->all());
    }

    // ---------- phone + OTP sign-in ----------

    public function test_sign_in_flow_and_the_token_belongs_only_to_the_customer_side(): void
    {
        $this->getJson('/api/v1/web/me')->assertStatus(401);
        $this->postJson('/api/v1/web/auth/code', ['phone' => '12345'])->assertStatus(422)->assertJsonPath('code', 'invalid_phone');

        $this->postJson('/api/v1/web/auth/code', ['phone' => '+880 1712-345678'])->assertOk()->assertJsonPath('data.sent', true);
        $code = $this->lastCode('01712345678');
        $this->assertStringNotContainsString($code, json_encode(Cache::get('otp:code:01712345678'))); // stored hashed

        // New customer: a right code is accepted, then the name is asked for; a wrong code reveals nothing.
        $this->postJson('/api/v1/web/auth/verify', ['phone' => '01712345678', 'code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'code_invalid');
        $this->postJson('/api/v1/web/auth/verify', ['phone' => '01712345678', 'code' => $code])->assertStatus(422)->assertJsonPath('code', 'name_required');
        $this->assertSame(0, Customer::count());
        $token = $this->postJson('/api/v1/web/auth/verify', ['phone' => '01712345678', 'code' => $code, 'name' => 'Ayesha'])->assertOk()->json('data.token');

        $this->assertSame(1, Customer::count());
        $this->assertDatabaseMissing('customer_sessions', ['token_hash' => $token]); // only the hash is stored
        $this->as($token)->getJson('/api/v1/web/me')->assertOk()->assertJsonPath('data.name', 'Ayesha');
        // The code is single-use.
        $this->postJson('/api/v1/web/auth/verify', ['phone' => '01712345678', 'code' => $code, 'name' => 'Xy'])->assertStatus(422)->assertJsonPath('code', 'code_expired');

        // A customer token opens no staff route, and a staff token opens no customer route.
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/orders')->assertStatus(401);
        $this->flushHeaders();
        $this->actingAsUserWithPermissions(['orders.view']);
        $this->getJson('/api/v1/web/me')->assertStatus(401);
    }

    public function test_a_code_dies_after_five_wrong_tries_and_sending_is_limited(): void
    {
        $this->postJson('/api/v1/web/auth/code', ['phone' => '01812345678'])->assertOk();
        $code = $this->lastCode('01812345678');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/web/auth/verify', ['phone' => '01812345678', 'code' => '111111', 'name' => 'Ab'])->assertStatus(422);
        }
        $this->postJson('/api/v1/web/auth/verify', ['phone' => '01812345678', 'code' => $code, 'name' => 'Ab'])->assertStatus(422)->assertJsonPath('code', 'code_expired');

        $this->postJson('/api/v1/web/auth/code', ['phone' => '01812345678'])->assertStatus(429)->assertJsonPath('code', 'code_cooldown');
        for ($i = 0; $i < 3; $i++) {
            Cache::forget('otp:cooldown:01812345678');
            $r = $this->postJson('/api/v1/web/auth/code', ['phone' => '01812345678']);
        }
        $r->assertStatus(429)->assertJsonPath('code', 'code_limit');
    }

    public function test_an_existing_customers_phone_in_any_format_is_matched_not_duplicated(): void
    {
        Customer::create(['name' => 'Rahim', 'phone' => '+8801912345678', 'is_active' => true]);
        $token = $this->signIn('01912345678', 'ignored');
        $this->assertSame(1, Customer::count());
        $this->as($token)->getJson('/api/v1/web/me')->assertJsonPath('data.name', 'Rahim');
    }

    // ---------- ordering ----------

    private function dish(float $price = 200): FoodItem
    {
        return $this->makeFoodItem($this->branch->id, $price);
    }

    private function orderBody(array|object $item, array $extra = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id, 'fulfilment' => 'pickup', 'payment' => 'cod',
            'items' => [['food_item_id' => is_array($item) ? $item['id'] : $item->id, 'quantity' => 2]],
        ], $extra);
    }

    public function test_ordering_is_closed_until_the_owner_opens_it(): void
    {
        $token = $this->signIn();
        $this->as($token)->postJson('/api/v1/web/orders', $this->orderBody($this->dish()))->assertStatus(422)->assertJsonPath('code', 'ordering_closed');
        $this->flushHeaders();
        $this->postJson('/api/v1/web/orders', $this->orderBody($this->dish()))->assertStatus(401);
    }

    public function test_a_server_priced_cash_on_delivery_order_ignores_any_client_money(): void
    {
        $this->openOrdering();
        $zone = DeliveryZone::create(['branch_id' => $this->branch->id, 'name' => 'Gulshan 1', 'delivery_fee' => 60, 'is_active' => true]);
        $dish = $this->dish(200);
        $token = $this->signIn();

        $body = $this->orderBody($dish, ['fulfilment' => 'delivery', 'delivery_zone_id' => $zone->id, 'delivery_address' => 'House 5, Road 2']);
        $body['items'][0]['unit_price'] = 1; // a hostile client
        $body['delivery_fee'] = 0;
        $body['discount_total'] = 399;
        $res = $this->as($token)->postJson('/api/v1/web/orders', $body)->assertStatus(201);

        $this->assertEquals(460.0, $res->json('data.total')); // 2 × 200 + 60 delivery
        $this->assertSame('received', $res->json('data.stage'));
        $order = Order::findOrFail($res->json('data.id'));
        $this->assertSame('web', $order->channel);
        $this->assertSame('delivery', $order->source);
        $this->assertNull($order->created_by);
        $this->assertDatabaseHas('delivery_assignments', ['order_id' => $order->id, 'status' => 'pending', 'delivery_address' => 'House 5, Road 2']);
        $this->assertSame(0, LoyaltyLedgerEntry::count()); // no points until it is actually finished
    }

    public function test_delivery_needs_a_zone_and_address_and_minimum_order_is_enforced(): void
    {
        $this->openOrdering(['web.min_order' => 500]);
        $dish = $this->dish(200);
        $token = $this->signIn();
        $this->as($token)->postJson('/api/v1/web/orders', $this->orderBody($dish, ['fulfilment' => 'delivery']))->assertStatus(422)->assertJsonPath('code', 'invalid_delivery_zone');
        $this->as($token)->postJson('/api/v1/web/orders', $this->orderBody($dish))->assertStatus(422)->assertJsonPath('code', 'below_minimum');
    }

    public function test_a_retried_request_with_the_same_idempotency_key_makes_one_order(): void
    {
        $this->openOrdering();
        $dish = $this->dish();
        $token = $this->signIn();
        $a = $this->as($token)->withHeader('Idempotency-Key', 'k1')->postJson('/api/v1/web/orders', $this->orderBody($dish))->assertStatus(201)->json('data.id');
        $b = $this->as($token)->withHeader('Idempotency-Key', 'k1')->postJson('/api/v1/web/orders', $this->orderBody($dish))->assertStatus(201)->json('data.id');
        $this->assertSame($a, $b);
        $this->assertSame(1, Order::count());
    }

    public function test_a_customer_sees_only_their_own_orders(): void
    {
        $this->openOrdering();
        $dish = $this->dish();
        $mine = $this->signIn('01712345678', 'Mine');
        $theirs = $this->signIn('01912345678', 'Theirs');
        $id = $this->as($mine)->postJson('/api/v1/web/orders', $this->orderBody($dish))->json('data.id');

        $this->as($mine)->getJson("/api/v1/web/orders/{$id}")->assertOk();
        $this->as($theirs)->getJson("/api/v1/web/orders/{$id}")->assertStatus(404);
        $this->as($theirs)->postJson("/api/v1/web/orders/{$id}/cancel")->assertStatus(404);
        $this->as($theirs)->postJson("/api/v1/web/orders/{$id}/review", ['rating' => 5])->assertStatus(404);
        $this->assertCount(0, $this->as($theirs)->getJson('/api/v1/web/orders')->json('data'));
    }

    public function test_cancelling_before_the_kitchen_starts_puts_the_stock_back(): void
    {
        $this->openOrdering();
        $dish = $this->dish();
        $ing = $this->giveRecipe($dish, $this->branch->id, 1, 10);
        $token = $this->signIn();
        $id = $this->as($token)->postJson('/api/v1/web/orders', $this->orderBody($dish))->assertStatus(201)->json('data.id');
        $this->assertEquals(8, $ing->stocks()->first()->quantity);

        $this->as($token)->postJson("/api/v1/web/orders/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertEquals(10, $ing->stocks()->first()->quantity);

        Order::whereKey($id)->update(['status' => 'pending', 'kitchen_state' => 'preparing']);
        $this->as($token)->postJson("/api/v1/web/orders/{$id}/cancel")->assertStatus(422)->assertJsonPath('code', 'cannot_cancel');
    }

    public function test_running_out_of_an_ingredient_stops_the_order_with_a_clear_message(): void
    {
        $this->openOrdering();
        $dish = $this->dish();
        $this->giveRecipe($dish, $this->branch->id, 1, 1); // 1 portion left, order wants 2
        $this->as($this->signIn())->postJson('/api/v1/web/orders', $this->orderBody($dish))->assertStatus(422)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame(0, Order::count());
    }

    public function test_online_payment_needs_a_configured_non_manual_gateway_and_returns_a_pay_link(): void
    {
        $this->openOrdering();
        $dish = $this->dish();
        $token = $this->signIn();
        $this->as($token)->postJson('/api/v1/web/orders', $this->orderBody($dish, ['payment' => 'online', 'gateway' => 'aamarpay']))->assertStatus(422)->assertJsonPath('code', 'gateway_unavailable');
        $this->assertSame(0, Order::count());

        PaymentGatewayConfig::updateOrCreate(['gateway_key' => 'aamarpay'], ['display_name' => 'aamarPay', 'is_enabled' => true, 'config' => ['store_id' => 'aamarpaytest', 'signature_key' => 's', 'sandbox' => '1']]);
        Http::swap(new Factory);
        Http::fake(['sandbox.aamarpay.com/jsonpost.php' => Http::response(['result' => 'true', 'payment_url' => 'https://sandbox.aamarpay.com/pay/abc'])]);
        $res = $this->as($token)->postJson('/api/v1/web/orders', $this->orderBody($dish, ['payment' => 'online', 'gateway' => 'aamarpay']))->assertStatus(201);
        $this->assertSame('https://sandbox.aamarpay.com/pay/abc', $res->json('data.payment.redirectUrl'));
        $this->assertEquals(400.0, $res->json('data.payment.amount'));

        $this->assertSame('aamarpay', collect($this->getJson('/api/v1/web/site')->json('data.ordering.online'))->first()['key']);
        PaymentGatewayConfig::updateOrCreate(['gateway_key' => 'bangla_qr'], ['display_name' => 'QR', 'is_enabled' => true, 'config' => ['qr_payload' => 'x', 'merchant_name' => 'M']]);
        $this->assertNotContains('bangla_qr', collect($this->getJson('/api/v1/web/site')->json('data.ordering.online'))->pluck('key')->all());
    }

    public function test_payment_callback_for_a_website_order_returns_to_the_website(): void
    {
        $this->openOrdering();
        config(['gateways.website_url' => 'https://shop.example.com']);
        PaymentGatewayConfig::updateOrCreate(['gateway_key' => 'aamarpay'], ['display_name' => 'aamarPay', 'is_enabled' => true, 'config' => ['store_id' => 'aamarpaytest', 'signature_key' => 's', 'sandbox' => '1']]);
        Http::swap(new Factory);
        Http::fake(['sandbox.aamarpay.com/jsonpost.php' => Http::response(['result' => 'true', 'payment_url' => 'https://x/y']), 'sandbox.aamarpay.com/api/v1/trxcheck/*' => Http::response(['pay_status' => 'Pending'])]);
        $token = $this->signIn();
        $res = $this->as($token)->postJson('/api/v1/web/orders', $this->orderBody($this->dish(), ['payment' => 'online', 'gateway' => 'aamarpay']))->assertStatus(201);
        $s = PaymentSession::findOrFail($res->json('data.payment.id'));
        $this->get("/api/v1/payment-callbacks/aamarpay/{$s->id}/{$s->callbackToken()}")
            ->assertStatus(303)->assertRedirectContains('https://shop.example.com/orders/'.$res->json('data.id'));
    }

    // ---------- loyalty, reservations, reviews ----------

    public function test_loyalty_points_arrive_only_when_the_order_is_finished_and_only_once(): void
    {
        $this->openOrdering();
        $token = $this->signIn();
        $id = $this->as($token)->postJson('/api/v1/web/orders', $this->orderBody($this->dish(250)))->json('data.id'); // 500
        $order = Order::findOrFail($id);

        $order->update(['status' => 'completed']);
        $order->update(['status' => 'delivered']);
        $this->as($token)->getJson('/api/v1/web/loyalty')->assertOk()->assertJsonPath('data.points', 5);
        $this->as($token)->getJson('/api/v1/web/me')->assertJsonPath('data.loyaltyPoints', 5);
    }

    public function test_reviews_only_for_own_finished_orders_once_and_start_unpublished(): void
    {
        $this->openOrdering();
        $token = $this->signIn();
        $id = $this->as($token)->postJson('/api/v1/web/orders', $this->orderBody($this->dish()))->json('data.id');
        $this->as($token)->postJson("/api/v1/web/orders/{$id}/review", ['rating' => 5])->assertStatus(422)->assertJsonPath('code', 'order_not_finished');

        Order::whereKey($id)->update(['status' => 'completed']);
        $this->as($token)->postJson("/api/v1/web/orders/{$id}/review", ['rating' => 9])->assertStatus(422);
        $this->as($token)->postJson("/api/v1/web/orders/{$id}/review", ['rating' => 5, 'comment' => 'Great <script>alert(1)</script>'])->assertStatus(201)->assertJsonPath('data.published', false);
        $this->as($token)->postJson("/api/v1/web/orders/{$id}/review", ['rating' => 4])->assertStatus(422)->assertJsonPath('code', 'already_reviewed');
        $this->assertStringNotContainsString('<script>', Review::first()->comment);

        $this->assertCount(0, $this->getJson("/api/v1/web/branches/{$this->branch->id}/reviews")->json('data.reviews'));
        Review::query()->update(['is_published' => true]);
        $this->getJson("/api/v1/web/branches/{$this->branch->id}/reviews")->assertJsonPath('data.count', 1)->assertJsonPath('data.average', 5);
    }

    public function test_reservation_requests_are_pending_limited_and_cancellable_by_their_owner_only(): void
    {
        $token = $this->signIn();
        $other = $this->signIn('01912345678', 'Other');
        $when = now()->addDays(3)->setTime(19, 0)->toDateTimeString();
        $body = ['branch_id' => $this->branch->id, 'party_size' => 4, 'reserved_for' => $when];

        $this->as($token)->postJson('/api/v1/web/reservations', array_merge($body, ['reserved_for' => now()->subDay()->toDateTimeString()]))->assertStatus(422);
        $id = $this->as($token)->postJson('/api/v1/web/reservations', $body)->assertStatus(201)->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->assertNull(Reservation::find($id)->dining_table_id); // no table is promised
        $this->as($other)->postJson("/api/v1/web/reservations/{$id}/cancel")->assertStatus(404);
        $this->as($token)->postJson("/api/v1/web/reservations/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        for ($i = 0; $i < 3; $i++) {
            $this->as($token)->postJson('/api/v1/web/reservations', $body)->assertStatus(201);
        }
        $this->as($token)->postJson('/api/v1/web/reservations', $body)->assertStatus(422)->assertJsonPath('code', 'too_many_reservations');

        app(AppSettings::class)->set(['features.reservations' => false], null);
        $this->as($token)->postJson('/api/v1/web/reservations', $body)->assertStatus(403);
    }

    // ---------- live tracking ----------

    private function usePusher(): void
    {
        config(['realtime.driver' => 'pusher', 'realtime.pusher' => ['app_id' => '1', 'key' => 'k', 'secret' => 's', 'cluster' => 'ap2', 'host' => null, 'port' => null, 'scheme' => 'https']]);
        app()->forgetInstance(Publisher::class);
    }

    public function test_status_endpoint_is_owner_only_and_answers_304_when_unchanged(): void
    {
        $this->openOrdering();
        $token = $this->signIn();
        $id = $this->as($token)->postJson('/api/v1/web/orders', $this->orderBody($this->dish()))->assertStatus(201)->json('data.id');

        $first = $this->as($token)->getJson("/api/v1/web/orders/{$id}/status")->assertOk()->assertJsonPath('data.stage', 'received');
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->as($token)->withHeader('If-None-Match', $etag)->getJson("/api/v1/web/orders/{$id}/status")->assertStatus(304);

        Order::whereKey($id)->update(['kitchen_state' => 'preparing', 'updated_at' => now()->addSecond()]);
        $this->as($token)->withHeader('If-None-Match', $etag)->getJson("/api/v1/web/orders/{$id}/status")->assertOk()->assertJsonPath('data.stage', 'preparing');

        $other = $this->signIn('01898765432', 'Other');
        $this->as($other)->getJson("/api/v1/web/orders/{$id}/status")->assertStatus(404);
    }

    public function test_customer_channel_auth_signs_only_the_customers_own_order(): void
    {
        $this->usePusher();
        $this->openOrdering();
        $token = $this->signIn();
        $id = $this->as($token)->postJson('/api/v1/web/orders', $this->orderBody($this->dish()))->assertStatus(201)->json('data.id');

        $ok = $this->as($token)->postJson('/api/v1/web/realtime/auth', ['socket_id' => '123.456', 'channel_name' => "private-order.{$id}"])->assertOk();
        $this->assertSame('k:'.hash_hmac('sha256', "123.456:private-order.{$id}", 's'), $ok->json('auth'));

        $this->as($token)->postJson('/api/v1/web/realtime/auth', ['socket_id' => '123.456', 'channel_name' => 'private-order.'.Str::uuid()])->assertStatus(403);
        $this->as($token)->postJson('/api/v1/web/realtime/auth', ['socket_id' => '123.456', 'channel_name' => 'private-branch.'.$this->branch->id])->assertStatus(403);
        $other = $this->signIn('01898765432', 'Other');
        $this->as($other)->postJson('/api/v1/web/realtime/auth', ['socket_id' => '123.456', 'channel_name' => "private-order.{$id}"])->assertStatus(403);
        $this->flushHeaders();
        $this->postJson('/api/v1/web/realtime/auth', ['socket_id' => '1.2', 'channel_name' => "private-order.{$id}"])->assertStatus(401);
    }

    public function test_a_web_order_change_pings_only_that_orders_channel_with_no_order_details(): void
    {
        $this->openOrdering();
        $token = $this->signIn();
        $id = $this->as($token)->postJson('/api/v1/web/orders', $this->orderBody($this->dish()))->assertStatus(201)->json('data.id');
        $this->usePusher();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('{}', 200)]);

        Order::findOrFail($id)->update(['kitchen_state' => 'preparing']);

        $sent = Http::recorded()->map(fn ($pair) => json_decode($pair[0]->body(), true))->filter(fn ($b) => ($b['channels'][0] ?? '') === "private-order.{$id}");
        $this->assertCount(1, $sent);
        $this->assertSame(['stage' => 'preparing'], json_decode($sent->first()['data'], true));
        $this->assertSame('{"stage":"preparing"}', $sent->first()['data']); // nothing else (no items, totals, phone)

        $this->getJson('/api/v1/web/realtime/config')->assertOk()->assertJsonPath('data.driver', 'pusher')->assertJsonMissingPath('data.secret');
    }

    // ---------- CMS images ----------

    public function test_cms_blocks_expose_only_existing_images_and_whitelisted_keys(): void
    {
        $img = Media::create(['path' => 'cms/a.jpg', 'original_filename' => 'a.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10, 'alt_text' => 'Dining room']);
        $pdf = Media::create(['path' => 'cms/a.pdf', 'original_filename' => 'a.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10]);
        CmsPage::create(['slug' => 'gallery', 'title' => 'G', 'is_published' => true, 'sections' => [
            ['type' => 'image', 'title' => 'Hello', 'mediaId' => $img->id, 'html' => '<script>x</script>', 'secret' => 'nope'],
            ['type' => 'gallery', 'mediaIds' => [$img->id, $pdf->id, (string) Str::uuid(), 'not-a-uuid']],
            ['type' => 'image', 'mediaId' => $pdf->id],
        ]]);

        $b = $this->getJson('/api/v1/web/pages/gallery')->assertOk()->json('data.sections');
        $this->assertSame('Dining room', $b[0]['image']['alt']);
        $this->assertStringContainsString('cms/a.jpg', $b[0]['image']['url']);
        $this->assertSame(['type', 'title', 'image'], array_keys($b[0])); // html/secret never leave
        $this->assertCount(1, $b[1]['images']); // pdf, unknown and malformed ids are dropped
        $this->assertSame(['type'], array_keys($b[2])); // a non-image can't be shown as an image
    }

    // ---------- address book ----------

    public function test_address_book_default_rules_limits_and_isolation(): void
    {
        $a = $this->signIn();
        $b = $this->signIn('01898765432', 'Other');

        $home = $this->as($a)->postJson('/api/v1/web/addresses', ['label' => 'Home', 'address' => 'House 1, Road 2, Gulshan'])->assertStatus(201)->assertJsonPath('data.isDefault', true)->json('data.id');
        $office = $this->as($a)->postJson('/api/v1/web/addresses', ['label' => '<b>Office</b>', 'address' => 'Level 5, Banani'])->assertStatus(201)->assertJsonPath('data.isDefault', false)->assertJsonPath('data.label', 'Office')->json('data.id');
        $this->assertSame('House 1, Road 2, Gulshan', Customer::where('phone', '01712345678')->value('address')); // mirrored for checkout

        $this->as($a)->putJson("/api/v1/web/addresses/{$office}", ['is_default' => true])->assertOk()->assertJsonPath('data.isDefault', true);
        $this->assertSame('Level 5, Banani', Customer::where('phone', '01712345678')->value('address'));
        $list = $this->as($a)->getJson('/api/v1/web/addresses')->assertOk()->json('data');
        $this->assertSame([true, false], collect($list)->pluck('isDefault')->all());

        // Another customer can neither see nor touch them.
        $this->assertSame([], $this->as($b)->getJson('/api/v1/web/addresses')->json('data'));
        $this->as($b)->putJson("/api/v1/web/addresses/{$home}", ['label' => 'Mine'])->assertStatus(404);
        $this->as($b)->deleteJson("/api/v1/web/addresses/{$home}")->assertStatus(404);

        // Deleting the default promotes another one.
        $this->as($a)->deleteJson("/api/v1/web/addresses/{$office}")->assertOk();
        $this->assertSame('House 1, Road 2, Gulshan', Customer::where('phone', '01712345678')->value('address'));
        $this->as($a)->deleteJson("/api/v1/web/addresses/{$home}")->assertOk();
        $this->assertNull(Customer::where('phone', '01712345678')->value('address'));

        for ($i = 0; $i < 10; $i++) {
            $this->as($a)->postJson('/api/v1/web/addresses', ['label' => "A{$i}", 'address' => "Somewhere number {$i}"])->assertStatus(201);
        }
        $this->as($a)->postJson('/api/v1/web/addresses', ['label' => 'X', 'address' => 'One too many'])->assertStatus(422)->assertJsonPath('code', 'too_many_addresses');
        $this->flushHeaders();
        $this->getJson('/api/v1/web/addresses')->assertStatus(401);
    }

    // ---------- order again ----------

    public function test_order_again_uses_todays_menu_and_reports_what_it_skipped(): void
    {
        $this->openOrdering();
        $kept = $this->dish();
        $gone = $this->makeFoodItem($this->branch->id, 90, ['name' => 'Seasonal Special']);
        $token = $this->signIn();
        $id = $this->as($token)->postJson('/api/v1/web/orders', $this->orderBody($kept, ['items' => [
            ['food_item_id' => $kept->id, 'quantity' => 3, 'notes' => 'less spicy'],
            ['food_item_id' => $gone->id, 'quantity' => 1],
        ]]))->assertStatus(201)->json('data.id');

        $kept->update(['base_price' => 300]); // price changed since
        $gone->update(['is_available' => false]);

        $r = $this->as($token)->getJson("/api/v1/web/orders/{$id}/reorder")->assertOk();
        $this->assertSame(['Seasonal Special'], $r->json('data.skipped'));
        $this->assertCount(1, $r->json('data.lines'));
        $this->assertSame(3, $r->json('data.lines.0.quantity'));
        $this->assertEquals(300, $r->json('data.lines.0.unitPrice')); // today's price, not the old snapshot
        $this->assertSame('less spicy', $r->json('data.lines.0.notes'));

        $other = $this->signIn('01898765432', 'Other');
        $this->as($other)->getJson("/api/v1/web/orders/{$id}/reorder")->assertStatus(404);
    }

    public function test_order_again_rebuilds_a_combo_with_its_quantity(): void
    {
        $this->openOrdering();
        $burger = $this->makeFoodItem($this->branch->id, 200, ['name' => 'Burger']);
        $fries = $this->makeFoodItem($this->branch->id, 100, ['name' => 'Fries']);
        $combo = Combo::create(['branch_id' => $this->branch->id, 'name' => 'Lunch', 'price' => 250]);
        $combo->items()->create(['food_item_id' => $burger->id, 'quantity' => 2]);
        $combo->items()->create(['food_item_id' => $fries->id, 'quantity' => 1]);
        $token = $this->signIn();
        $id = $this->as($token)->postJson('/api/v1/web/orders', ['branch_id' => $this->branch->id, 'fulfilment' => 'pickup', 'payment' => 'cod', 'items' => [['combo_id' => $combo->id, 'quantity' => 3]]])->assertStatus(201)->json('data.id');

        $r = $this->as($token)->getJson("/api/v1/web/orders/{$id}/reorder")->assertOk();
        $this->assertSame([], $r->json('data.skipped'));
        $this->assertSame($combo->id, $r->json('data.lines.0.comboId'));
        $this->assertSame(3, $r->json('data.lines.0.quantity'));
    }

    // ---------- customer app: push token ----------

    public function test_customer_app_registers_a_push_token_that_only_its_owner_can_remove_and_a_shared_phone_moves_it(): void
    {
        $a = $this->signIn('01712345678', 'Ayesha');
        $this->as($a)->postJson('/api/v1/web/device-tokens', ['token' => 'tok-1', 'platform' => 'web'])->assertStatus(201);
        $ayesha = Customer::where('phone', BdPhone::normalize('01712345678'))->firstOrFail();
        $this->assertDatabaseHas('device_tokens', ['token' => 'tok-1', 'owner_type' => Customer::class, 'owner_id' => $ayesha->id]);

        $b = $this->signIn('01898765432', 'Bilal');
        $bilal = Customer::where('phone', BdPhone::normalize('01898765432'))->firstOrFail();
        // someone else cannot delete Ayesha's token
        $this->as($b)->deleteJson('/api/v1/web/device-tokens', ['token' => 'tok-1'])->assertNoContent();
        $this->assertDatabaseHas('device_tokens', ['token' => 'tok-1', 'owner_id' => $ayesha->id]);
        // but registering it from a shared phone moves it, so Ayesha stops getting Bilal's alerts
        $this->as($b)->postJson('/api/v1/web/device-tokens', ['token' => 'tok-1', 'platform' => 'web'])->assertStatus(201);
        $this->assertDatabaseHas('device_tokens', ['token' => 'tok-1', 'owner_id' => $bilal->id]);
        $this->as($b)->deleteJson('/api/v1/web/device-tokens', ['token' => 'tok-1'])->assertNoContent();
        $this->assertDatabaseMissing('device_tokens', ['token' => 'tok-1']);

        $this->flushHeaders();
        $this->postJson('/api/v1/web/device-tokens', ['token' => 'x', 'platform' => 'web'])->assertStatus(401);
    }
}
