<?php

namespace Tests\Feature\Pos;

use App\Models\KitchenStation;
use App\Models\OrderItem;
use App\Models\Printer;
use App\Support\EscPos;
use Tests\Concerns\TakesPayments;
use Tests\TestCase;

class PrinterReceiptTest extends TestCase
{
    use TakesPayments;

    public function test_printer_crud_and_lan_only_network_addresses(): void
    {
        $this->actingAsUserWithPermissions(['printers.manage']);

        $this->postJson('/api/v1/printers', ['name' => 'Counter', 'connection' => 'network', 'role' => 'receipt', 'host' => '192.168.1.50'])
            ->assertStatus(201)->assertJsonPath('data.port', 9100);

        foreach (['169.254.169.254', '127.0.0.1', '8.8.8.8', 'printer.example.com'] as $bad) {
            $this->postJson('/api/v1/printers', ['name' => 'Bad', 'connection' => 'network', 'role' => 'receipt', 'host' => $bad])
                ->assertStatus(422)->assertJsonPath('code', 'validation_error');
        }

        $this->postJson('/api/v1/printers', ['name' => 'Browser', 'connection' => 'browser', 'role' => 'receipt'])->assertStatus(201);
        $this->getJson('/api/v1/printers')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_printers_are_branch_isolated_and_permissioned(): void
    {
        $user = $this->actingAsUserWithPermissions(['printers.manage']);
        $p = Printer::create(['branch_id' => $this->createBranch()->id, 'name' => 'Other', 'connection' => 'browser', 'role' => 'receipt']);

        $this->putJson("/api/v1/printers/{$p->id}", ['name' => 'Hacked'])->assertStatus(404);
        $this->actingAsUserWithPermissions(['orders.view'], $user->activeBranch);
        $this->getJson('/api/v1/printers')->assertStatus(403);
    }

    public function test_receipt_document_shows_totals_payments_and_change(): void
    {
        [, $order] = $this->cashierWithOrder();
        $this->openShift();
        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'cash', 'tip' => 10, 'tendered' => 250])->assertStatus(201);

        $this->getJson("/api/v1/orders/{$order->id}/receipt")->assertOk()
            ->assertJsonPath('data.code', $order->code)->assertJsonPath('data.grandTotal', 200)
            ->assertJsonPath('data.payments.0.change', 40)->assertJsonPath('data.balanceDue', 0);
    }

    public function test_print_to_a_browser_printer_returns_escpos_bytes_and_reprints_are_audited(): void
    {
        [$user, $order] = $this->cashierWithOrder(['printers.manage']);
        $printer = Printer::create(['branch_id' => $user->active_branch_id, 'name' => 'Receipt', 'connection' => 'usb', 'role' => 'receipt', 'paper_width' => 58]);

        $res = $this->postJson("/api/v1/orders/{$order->id}/print", ['printer_id' => $printer->id, 'type' => 'receipt', 'copy' => true])
            ->assertOk()->assertJsonPath('data.delivery', 'client');
        $bytes = base64_decode($res->json('data.escposBase64'));

        $this->assertStringStartsWith("\x1B@", $bytes);
        $this->assertStringContainsString('DUPLICATE COPY', $bytes);
        $this->assertStringContainsString($order->code, $bytes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.receipt_reprinted', 'subject_id' => $order->id]);
    }

    public function test_unreachable_network_printer_falls_back_to_client_with_a_note(): void
    {
        [$user, $order] = $this->cashierWithOrder();
        $printer = Printer::create(['branch_id' => $user->active_branch_id, 'name' => 'Net', 'connection' => 'network', 'role' => 'receipt', 'host' => '10.255.255.1', 'port' => 9]);

        $this->postJson("/api/v1/orders/{$order->id}/print", ['printer_id' => $printer->id, 'type' => 'receipt'])
            ->assertOk()->assertJsonPath('data.delivery', 'client')->assertJson(fn ($j) => $j->has('data.note')->etc());
    }

    public function test_kitchen_ticket_only_includes_the_stations_dishes(): void
    {
        [$user, $order, $dish] = $this->cashierWithOrder();
        $station = KitchenStation::create(['branch_id' => $user->active_branch_id, 'name' => 'Grill']);
        OrderItem::where('order_id', $order->id)->update(['kitchen_station_id' => $station->id]);
        $other = KitchenStation::create(['branch_id' => $user->active_branch_id, 'name' => 'Bar']);

        $grill = Printer::create(['branch_id' => $user->active_branch_id, 'name' => 'G', 'connection' => 'usb', 'role' => 'kitchen', 'kitchen_station_id' => $station->id]);
        $bar = Printer::create(['branch_id' => $user->active_branch_id, 'name' => 'B', 'connection' => 'usb', 'role' => 'kitchen', 'kitchen_station_id' => $other->id]);

        $g = base64_decode($this->postJson("/api/v1/orders/{$order->id}/print", ['printer_id' => $grill->id, 'type' => 'kitchen'])->json('data.escposBase64'));
        $b = base64_decode($this->postJson("/api/v1/orders/{$order->id}/print", ['printer_id' => $bar->id, 'type' => 'kitchen'])->json('data.escposBase64'));

        $this->assertStringContainsString('2 x', $g);
        $this->assertStringNotContainsString('2 x', $b);
        $this->postJson("/api/v1/orders/{$order->id}/print", ['printer_id' => $grill->id, 'type' => 'receipt'])->assertStatus(422)->assertJsonPath('code', 'wrong_printer_role');
    }

    public function test_escpos_helpers_sanitise_text_and_pad_columns(): void
    {
        $this->assertSame('Tk 500 ???', EscPos::ascii('৳ 500 বাং'));
        $this->assertSame(20, strlen(EscPos::pair('Tea', '12.00', 20)));
        $this->assertSame(20, strlen(EscPos::pair(str_repeat('x', 40), '12.00', 20)));
    }
}
