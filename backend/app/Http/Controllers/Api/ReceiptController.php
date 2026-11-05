<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Printer;
use App\Services\AppSettings;
use App\Services\OrderLedger;
use App\Support\Bn;
use App\Support\EscPos;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Receipts and kitchen tickets. The server builds one structured document and renders it as
 * (a) JSON for browser printing and (b) ESC/POS bytes. Delivery to a *network* printer is attempted
 * from the server only when it can reach the LAN; otherwise (shared hosting!) the bytes come back
 * base64-encoded for a local print agent / browser bridge, and the response says which happened.
 */
class ReceiptController extends Controller
{
    /** GET /orders/{order}/receipt */
    public function show(Order $order)
    {
        Gate::authorize('view', $order);

        return response()->json(['data' => $this->document($order)]);
    }

    /** POST /orders/{order}/print {printer_id, type: receipt|kitchen, copy?: bool} */
    public function print(Request $request, Order $order)
    {
        Gate::authorize('view', $order);
        $data = $request->validate([
            'printer_id' => ['required', 'uuid'],
            'type' => ['required', 'in:receipt,kitchen'],
            'copy' => ['sometimes', 'boolean'],
        ]);

        $printer = Printer::where('id', $data['printer_id'])->where('branch_id', $order->branch_id)->where('is_active', true)->first();
        if (! $printer) {
            return response()->json(['code' => 'printer_not_found', 'message' => 'That printer is not available.'], 422);
        }
        if ($printer->role !== $data['type']) {
            return response()->json(['code' => 'wrong_printer_role', 'message' => "That printer is set up for {$printer->role} printing."], 422);
        }

        $copy = (bool) ($data['copy'] ?? false);
        $bytes = $data['type'] === 'receipt'
            ? $this->receiptBytes($order, $printer, $copy)
            : $this->kitchenBytes($order, $printer);

        $this->recordAudit($request, $copy ? 'order.receipt_reprinted' : 'order.printed', $order, ['type' => $data['type'], 'printer' => $printer->name]);

        $delivery = 'client';
        $note = null;
        if ($printer->connection === 'network') {
            $sent = $this->sendToNetworkPrinter($printer, $bytes);
            if ($sent === true) {
                $delivery = 'server';
            } else {
                $note = $sent;
            }
        }

        return response()->json(['data' => [
            'delivery' => $delivery, // server = sent to the printer; client = print it from the browser/agent with escposBase64
            'escposBase64' => $delivery === 'client' ? base64_encode($bytes) : null,
            'note' => $note,
        ]]);
    }

    private function document(Order $order): array
    {
        $order->load(['items', 'branch', 'diningTable']);
        $payments = Payment::where('order_id', $order->id)->whereNull('voided_at')->orderBy('created_at')->get();
        $paid = $payments->where('kind', 'payment');
        $refunded = $payments->where('kind', 'refund');

        $cfg = app(AppSettings::class);
        $locale = (string) $cfg->get('receipt.language', 'en');
        $tz = (string) $cfg->get('locale.timezone', 'Asia/Dhaka');
        $loc = fn (string $v) => Bn::localise($v, $locale);

        return [
            'locale' => $locale,
            'labels' => __('receipt', [], $locale),
            'currencySymbol' => $cfg->get('currency.symbol', '৳'),
            'business' => $order->branch?->name,
            'legalName' => $cfg->get('business.legal_name') ?: null,
            'address' => $cfg->get('business.address') ?: null,
            'phone' => $cfg->get('business.phone') ?: null,
            'taxId' => $cfg->get('receipt.show_tax_id') && $cfg->get('business.tax_id') ? $cfg->get('business.tax_id') : null,
            'header' => $cfg->get('receipt.header') ?: null,
            'code' => $order->code,
            'date' => $order->created_at ? $loc($order->created_at->copy()->timezone($tz)->locale($locale)->translatedFormat('d M Y, h:i A')) : null,
            'source' => $order->source,
            'table' => $order->diningTable?->label,
            'items' => $order->items->map(fn ($i) => [
                'name' => $i->name, 'quantity' => $i->quantity, 'total' => (float) $i->total,
                'modifiers' => collect($i->modifiers ?? [])->pluck('name')->all(), 'notes' => $i->notes,
            ])->all(),
            'subtotal' => (float) $order->subtotal,
            'discount' => (float) $order->discount_total,
            'tax' => (float) $order->tax_total,
            'taxIncluded' => (float) $order->tax_inclusive_total,
            'deliveryFee' => (float) $order->delivery_fee,
            'grandTotal' => (float) $order->grand_total,
            'payments' => $paid->map(fn ($p) => [
                'method' => $p->method, 'amount' => (float) $p->amount, 'tip' => (float) $p->tip,
                'tendered' => $p->tendered === null ? null : (float) $p->tendered,
                'change' => $p->change_given === null ? null : (float) $p->change_given,
            ])->values()->all(),
            'refunded' => Money::toFloat($refunded->sum(fn ($p) => Money::toMinor($p->amount))),
            'balanceDue' => Money::toFloat(app(OrderLedger::class)->balanceMinor($order)),
            'footer' => $cfg->get('receipt.footer') ?: config('pos.receipt_footer'),
        ];
    }

    private function money(float $v): string
    {
        return number_format($v, 2);
    }

    private function receiptBytes(Order $order, Printer $printer, bool $copy): string
    {
        $d = $this->document($order);
        $w = $printer->columns();
        $p = (new EscPos)->init();

        $p->align('center')->bold(true)->doubleSize(true)->line($d['business'] ?? 'Chefs Trolley')->doubleSize(false)->bold(false);
        if ($copy) {
            $p->bold(true)->line('*** DUPLICATE COPY ***')->bold(false);
        }
        $p->line("Order {$d['code']}")->line($d['date'] ?? '');
        if ($d['table']) {
            $p->line("Table {$d['table']}");
        }
        $p->align('left')->line(str_repeat('-', $w));

        foreach ($d['items'] as $i) {
            $p->line(EscPos::pair("{$i['quantity']} x {$i['name']}", $this->money($i['total']), $w));
            foreach ($i['modifiers'] as $m) {
                $p->line('   + '.$m);
            }
        }
        $p->line(str_repeat('-', $w));
        $p->line(EscPos::pair('Subtotal', $this->money($d['subtotal']), $w));
        if ($d['discount'] > 0) {
            $p->line(EscPos::pair('Discount', '-'.$this->money($d['discount']), $w));
        }
        if ($d['tax'] - $d['taxIncluded'] > 0) {
            $p->line(EscPos::pair('Tax', $this->money($d['tax'] - $d['taxIncluded']), $w));
        }
        if ($d['deliveryFee'] > 0) {
            $p->line(EscPos::pair('Delivery', $this->money($d['deliveryFee']), $w));
        }
        $p->bold(true)->line(EscPos::pair('TOTAL (BDT)', $this->money($d['grandTotal']), $w))->bold(false);
        if ($d['taxIncluded'] > 0) {
            $p->line(EscPos::pair('(incl. tax)', $this->money($d['taxIncluded']), $w));
        }

        foreach ($d['payments'] as $pay) {
            $p->line(EscPos::pair('Paid '.str_replace('_', ' ', $pay['method']), $this->money($pay['amount']), $w));
            if ($pay['tip'] > 0) {
                $p->line(EscPos::pair('  Tip', $this->money($pay['tip']), $w));
            }
            if ($pay['change']) {
                $p->line(EscPos::pair('  Change', $this->money($pay['change']), $w));
            }
        }
        if ($d['refunded'] > 0) {
            $p->line(EscPos::pair('Refunded', $this->money($d['refunded']), $w));
        }
        if ($d['balanceDue'] > 0) {
            $p->bold(true)->line(EscPos::pair('BALANCE DUE', $this->money($d['balanceDue']), $w))->bold(false);
        }

        $p->line(str_repeat('-', $w))->align('center')->line($d['footer'] ?? '')->feed(3)->cut();
        if ($printer->opens_drawer && ! $copy && collect($d['payments'])->contains(fn ($x) => $x['method'] === 'cash')) {
            $p->openDrawer();
        }

        return $p->bytes();
    }

    private function kitchenBytes(Order $order, Printer $printer): string
    {
        $order->load(['items', 'diningTable']);
        $w = $printer->columns();
        $items = $order->items->filter(fn ($i) => ! $printer->kitchen_station_id || $i->kitchen_station_id === $printer->kitchen_station_id);

        $p = (new EscPos)->init()->align('center')->bold(true)->doubleSize(true)->line("#{$order->code}")->doubleSize(false)->bold(false);
        $p->line(strtoupper(str_replace('_', ' ', $order->source)).($order->diningTable ? " - Table {$order->diningTable->label}" : ''))
            ->line($order->created_at?->copy()->timezone('Asia/Dhaka')->format('h:i A') ?? '')
            ->align('left')->line(str_repeat('=', $w));

        foreach ($items as $i) {
            $p->bold(true)->line("{$i->quantity} x {$i->name}")->bold(false);
            foreach ($i->modifiers ?? [] as $m) {
                $p->line('   + '.($m['name'] ?? ''));
            }
            if ($i->notes) {
                $p->line('   NOTE: '.$i->notes);
            }
        }

        return $p->feed(3)->cut()->bytes();
    }

    /** @return true|string true when sent, otherwise why not */
    private function sendToNetworkPrinter(Printer $printer, string $bytes): bool|string
    {
        if (! $printer->host || ! PrinterController::isLanAddress($printer->host)) {
            return 'Printer address is not a LAN address.';
        }
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($printer->host, $printer->port, $errno, $errstr, 2.0);
        if (! $socket) {
            return "Could not reach {$printer->host}:{$printer->port} from the server (it is usually only reachable from inside the restaurant network). Print from the browser or a local print agent instead.";
        }
        stream_set_timeout($socket, 3);
        $written = @fwrite($socket, $bytes);
        fclose($socket);

        return $written === strlen($bytes) ? true : 'The printer accepted the connection but not all of the data.';
    }
}
