<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Printer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PrinterController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $this->requireBranch($request);
        if (! is_string($branchId)) {
            return $branchId;
        }

        return response()->json(['data' => Printer::where('branch_id', $branchId)->orderBy('name')->get()->map(fn ($p) => $this->serialize($p))]);
    }

    public function store(Request $request)
    {
        $branchId = $this->requireBranch($request);
        if (! is_string($branchId)) {
            return $branchId;
        }
        $printer = Printer::create($this->validated($request, $branchId) + ['branch_id' => $branchId]);
        $this->recordAudit($request, 'printer.created', $printer);

        return response()->json(['data' => $this->serialize($printer)], 201);
    }

    public function update(Request $request, Printer $printer)
    {
        $printer->update($this->validated($request, $printer->branch_id, $printer));
        $this->recordAudit($request, 'printer.updated', $printer);

        return response()->json(['data' => $this->serialize($printer->fresh())]);
    }

    public function destroy(Request $request, Printer $printer)
    {
        $this->recordAudit($request, 'printer.deleted', $printer);
        $printer->delete();

        return response()->noContent();
    }

    private function validated(Request $request, string $branchId, ?Printer $existing = null): array
    {
        $sometimes = $existing ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$sometimes, 'string', 'max:60'],
            'connection' => [$sometimes, 'in:network,usb,browser'],
            'role' => [$sometimes, 'in:receipt,kitchen'],
            'host' => ['nullable', 'string', 'max:45'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'kitchen_station_id' => ['nullable', 'uuid', Rule::exists('kitchen_stations', 'id')->where('branch_id', $branchId)],
            'paper_width' => ['nullable', 'in:58,80'],
            'opens_drawer' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $connection = $data['connection'] ?? $existing?->connection;
        $host = array_key_exists('host', $data) ? $data['host'] : $existing?->host;
        if ($connection === 'network') {
            // The server connects to this address, so it must be a LAN address: never loopback, link-local
            // (cloud metadata lives at 169.254.169.254) or public — this closes off SSRF through the printer setting.
            if (! $host || ! self::isLanAddress($host)) {
                return abort(response()->json([
                    'code' => 'validation_error', 'message' => 'The given data was invalid.',
                    'errors' => ['host' => ['A network printer needs a private LAN IPv4 address such as 192.168.1.50.']],
                ], 422));
            }
        }
        if (isset($data['paper_width'])) {
            $data['paper_width'] = (int) $data['paper_width'];
        }

        return $data;
    }

    public static function isLanAddress(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $isPublic = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        $isLinkLocal = str_starts_with($ip, '169.254.');
        $isLoopback = str_starts_with($ip, '127.') || $ip === '0.0.0.0';

        return ! $isPublic && ! $isLinkLocal && ! $isLoopback;
    }

    private function serialize(Printer $p): array
    {
        return [
            'id' => $p->id, 'branchId' => $p->branch_id, 'name' => $p->name, 'connection' => $p->connection, 'role' => $p->role,
            'host' => $p->host, 'port' => $p->port, 'kitchenStationId' => $p->kitchen_station_id,
            'paperWidth' => $p->paper_width, 'opensDrawer' => $p->opens_drawer, 'isActive' => $p->is_active,
        ];
    }
}
