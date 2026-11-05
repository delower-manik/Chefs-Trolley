<?php

namespace Tests\Feature\Platform;

use App\Models\Customer;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Services\AppSettings;
use App\Support\EscPos;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\TakesPayments;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use TakesPayments;

    public function test_settings_need_the_permission_but_the_public_view_is_for_everyone_signed_in(): void
    {
        $this->actingAsUserWithPermissions(['orders.view']);
        $this->getJson('/api/v1/settings')->assertStatus(403);
        $this->putJson('/api/v1/settings', ['business.name' => 'X'])->assertStatus(403);
        $this->getJson('/api/v1/settings/public')->assertOk()
            ->assertJsonPath('data.currency.code', 'BDT')->assertJsonPath('data.locale', 'en')->assertJsonPath('data.features.gift_cards', true);
    }

    public function test_defaults_update_validation_and_audit(): void
    {
        $this->actingAsUserWithPermissions(['settings.manage']);
        $this->getJson('/api/v1/settings')->assertOk()->assertJsonPath('data.business.name', 'Chefs Trolley')->assertJsonPath('data.currency.decimals', 0)->assertJsonPath('data.features.wifi', true);

        $this->putJson('/api/v1/settings', ['business.name' => 'Chefs Trolley Chuadanga', 'business.tax_id' => '1234567890', 'locale.default' => 'bn', 'currency.decimals' => 2, 'features.wifi' => false])
            ->assertOk()->assertJsonPath('data.business.name', 'Chefs Trolley Chuadanga')->assertJsonPath('data.locale.default', 'bn')
            ->assertJsonPath('data.currency.decimals', 2)->assertJsonPath('data.features.wifi', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);

        $this->putJson('/api/v1/settings', ['locale.default' => 'fr'])->assertStatus(422)->assertJsonPath('code', 'validation_error');
        $this->putJson('/api/v1/settings', ['locale.timezone' => 'Mars/Olympus'])->assertStatus(422);
        $this->putJson('/api/v1/settings', ['currency.decimals' => 5])->assertStatus(422);
        $this->putJson('/api/v1/settings', ['business.email' => 'not-an-email'])->assertStatus(422);
        $this->putJson('/api/v1/settings', ['business.name' => ''])->assertStatus(422);
        $this->putJson('/api/v1/settings', ['made.up' => 'x'])->assertStatus(422)->assertJsonPath('code', 'unknown_setting');
        $this->assertSame('Chefs Trolley Chuadanga', app(AppSettings::class)->get('business.name')); // failed requests changed nothing
    }

    public function test_switching_a_module_off_hides_it_from_the_api_and_the_till(): void
    {
        $user = $this->actingAsUserWithPermissions(array_merge($this->posPerms, ['settings.manage', 'giftcards.view', 'giftcards.issue', 'campaigns.manage', 'reservations.manage', 'integrations.manage']));
        $this->getJson('/api/v1/gift-cards')->assertOk();
        $this->getJson('/api/v1/campaigns')->assertOk();
        $this->getJson('/api/v1/reservations')->assertOk();
        $this->getJson('/api/v1/wifi-zones')->assertOk();

        $this->putJson('/api/v1/settings', ['features.gift_cards' => false, 'features.campaigns' => false, 'features.reservations' => false, 'features.wifi' => false])->assertOk();

        foreach (['/api/v1/gift-cards', '/api/v1/campaigns', '/api/v1/customer-segments', '/api/v1/reservations', '/api/v1/wifi-zones'] as $url) {
            $this->getJson($url)->assertStatus(403)->assertJsonPath('code', 'feature_disabled');
        }
        $this->getJson('/api/v1/orders')->assertOk(); // everything else is untouched

        $dish = $this->makeFoodItem($user->active_branch_id, 100);
        $id = $this->postJson('/api/v1/orders', ['source' => 'pos', 'items' => [['food_item_id' => $dish->id, 'quantity' => 1]]])->json('data.id');
        $this->postJson("/api/v1/orders/{$id}/payments", ['method' => 'gift_card', 'gift_card_code' => 'ABCD-EFGH'])->assertStatus(422)->assertJsonPath('code', 'feature_disabled');
        $this->openShift();
        $this->postJson("/api/v1/orders/{$id}/payments", ['method' => 'cash', 'amount' => 100])->assertStatus(201);
    }

    public function test_receipts_follow_the_business_profile_and_language(): void
    {
        [$user, $order] = $this->cashierWithOrder(['settings.manage']);
        $en = $this->getJson("/api/v1/orders/{$order->id}/receipt")->assertOk();
        $en->assertJsonPath('data.locale', 'en')->assertJsonPath('data.labels.total', 'Total')->assertJsonPath('data.taxId', null);

        $this->putJson('/api/v1/settings', [
            'business.legal_name' => 'Chefs Trolley Ltd', 'business.address' => 'Chuadanga', 'business.phone' => '01700000000', 'business.tax_id' => '998877',
            'receipt.language' => 'bn', 'receipt.header' => 'স্বাগতম', 'receipt.footer' => 'আবার আসবেন', 'locale.timezone' => 'Asia/Dhaka',
        ])->assertOk();
        $bn = $this->getJson("/api/v1/orders/{$order->id}/receipt")->assertOk()->json('data');
        $this->assertSame('bn', $bn['locale']);
        $this->assertSame('সর্বমোট', $bn['labels']['total']);
        $this->assertSame('নগদ', $bn['labels']['methods']['cash']);
        $this->assertSame('Chefs Trolley Ltd', $bn['legalName']);
        $this->assertSame('998877', $bn['taxId']);
        $this->assertSame('আবার আসবেন', $bn['footer']);
        $this->assertMatchesRegularExpression('/[০-৯]/u', $bn['date']);      // digits are Bengali
        $this->assertDoesNotMatchRegularExpression('/[0-9]/', $bn['date']);

        $this->putJson('/api/v1/settings', ['receipt.show_tax_id' => false])->assertOk();
        $this->assertNull($this->getJson("/api/v1/orders/{$order->id}/receipt")->json('data.taxId'));
    }

    public function test_thermal_printing_never_sends_bengali_glyphs_it_cannot_draw(): void
    {
        EscPos::ascii('x'); // exists
        $this->assertSame('Thank you ???', EscPos::ascii('Thank you ধন্'));
    }

    public function test_order_texts_use_the_customers_language_then_the_business_default(): void
    {
        config(['notify.order_sms' => true, 'notify.sms.driver' => 'greenweb', 'notify.sms.greenweb.token' => 'TK']);
        Http::fake(['*' => Http::response('Ok', 200)]);
        $user = $this->actingAsUserWithPermissions(array_merge($this->posPerms, ['settings.manage']));
        $dish = $this->makeFoodItem($user->active_branch_id, 100);

        $place = function (Customer $c) use ($dish) {
            return Order::findOrFail($this->postJson('/api/v1/orders', ['source' => 'delivery', 'customer_id' => $c->id, 'items' => [['food_item_id' => $dish->id, 'quantity' => 1]]])->assertStatus(201)->json('data.id'));
        };

        $a = Customer::create(['name' => 'A', 'phone' => '01711111111']);
        $place($a);
        $this->assertStringContainsString('We received order', NotificationDelivery::latest('created_at')->first()->body);

        $b = Customer::create(['name' => 'B', 'phone' => '01722222222', 'locale' => 'bn']);
        $place($b);
        $body = NotificationDelivery::where('customer_id', $b->id)->first()->body;
        $this->assertStringContainsString('আপনার অর্ডার', $body);
        $this->assertMatchesRegularExpression('/[০-৯]/u', $body);

        $this->putJson('/api/v1/settings', ['locale.default' => 'bn'])->assertOk();
        $c = Customer::create(['name' => 'C', 'phone' => '01733333333']);
        $place($c);
        $this->assertStringContainsString('আপনার অর্ডার', NotificationDelivery::where('customer_id', $c->id)->first()->body);
    }

    public function test_customer_language_can_be_saved_and_is_validated(): void
    {
        $user = $this->actingAsUserWithPermissions(['customers.manage', 'customers.view']);
        $res = $this->postJson('/api/v1/customers', ['name' => 'Rafi', 'phone' => '01755555555', 'locale' => 'bn'])->assertStatus(201);
        $this->assertSame('bn', $res->json('data.locale'));
        $this->postJson('/api/v1/customers', ['name' => 'Rafi2', 'phone' => '01766666666', 'locale' => 'xx'])->assertStatus(422);
    }
}
