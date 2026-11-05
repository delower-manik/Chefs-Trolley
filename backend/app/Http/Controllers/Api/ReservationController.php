<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveReservationRequest;
use App\Models\AuditLog;
use App\Models\DiningTable;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservationController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $query = Reservation::query()->with('table')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('reserved_for', $request->query('date')))
            ->orderBy('reserved_for');

        $perPage = min((int) $request->query('per_page', 20), 100);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map($this->serialize(...)),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(SaveReservationRequest $request)
    {
        $tableId = $request->validated('dining_table_id');
        $reservedFor = $request->validated('reserved_for');
        $duration = $request->validated('duration_minutes', 90);

        // The table row is locked while we check-and-write, so two staff booking the same table at once cannot both succeed.
        $reservation = DB::transaction(function () use ($request, $tableId, $reservedFor, $duration) {
            if ($tableId) {
                DiningTable::whereKey($tableId)->lockForUpdate()->first();
            }
            if (Reservation::hasConflict($tableId, new \DateTime($reservedFor), $duration)) {
                return null;
            }

            return Reservation::create([
                ...$request->validated(),
                'created_by' => $request->user()->id,
                'status' => 'pending',
            ]);
        });
        if (! $reservation) {
            return $this->doubleBooked();
        }

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'reservation.created',
            subjectType: Reservation::class,
            subjectId: $reservation->id,
            branchId: $reservation->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($reservation->load('table'))], 201);
    }

    public function update(SaveReservationRequest $request, Reservation $reservation)
    {
        $tableId = $request->validated('dining_table_id');
        $reservedFor = $request->validated('reserved_for');
        $duration = $request->validated('duration_minutes', $reservation->duration_minutes);

        $before = $reservation->only(['status', 'reserved_for', 'party_size']);
        $saved = DB::transaction(function () use ($request, $reservation, $tableId, $reservedFor, $duration) {
            if ($tableId) {
                DiningTable::whereKey($tableId)->lockForUpdate()->first();
            }
            if (Reservation::hasConflict($tableId, new \DateTime($reservedFor), $duration, excludeId: $reservation->id)) {
                return false;
            }
            $reservation->update($request->validated());

            return true;
        });
        if (! $saved) {
            return $this->doubleBooked();
        }

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'reservation.updated',
            subjectType: Reservation::class,
            subjectId: $reservation->id,
            branchId: $reservation->branch_id,
            changes: ['before' => $before, 'after' => $reservation->fresh()->only(['status', 'reserved_for', 'party_size'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($reservation->fresh('table'))]);
    }

    public function cancel(Request $request, Reservation $reservation)
    {
        if (! $reservation->isCancellable()) {
            return response()->json([
                'code' => 'invalid_reservation_state',
                'message' => 'This reservation can no longer be cancelled.',
            ], 422);
        }

        $reservation->update(['status' => 'cancelled']);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'reservation.cancelled',
            subjectType: Reservation::class,
            subjectId: $reservation->id,
            branchId: $reservation->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($reservation->fresh('table'))]);
    }

    private function serialize(Reservation $reservation): array
    {
        return [
            'id' => $reservation->id,
            'branchId' => $reservation->branch_id,
            'tableId' => $reservation->dining_table_id,
            'tableLabel' => $reservation->table?->label,
            'customerName' => $reservation->customer_name,
            'customerPhone' => $reservation->customer_phone,
            'partySize' => $reservation->party_size,
            'reservedFor' => $reservation->reserved_for,
            'durationMinutes' => $reservation->duration_minutes,
            'status' => $reservation->status,
            'notes' => $reservation->notes,
        ];
    }

    private function doubleBooked()
    {
        return response()->json([
            'code' => 'table_double_booked',
            'message' => 'This table already has a reservation that overlaps this time. Pick another table or time.',
        ], 422);
    }
}
