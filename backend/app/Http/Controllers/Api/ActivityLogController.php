<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\AdminAccess;
use App\Support\DateRange;
use App\Support\Like;
use Illuminate\Http\Request;

/**
 * Read-only view of the audit trail. There is intentionally no way to edit
 * or delete an entry (the model itself refuses).
 *
 * Visibility: a Super Admin sees every entry, optionally narrowed to one
 * branch. Anyone else sees only entries recorded against their active
 * branch -- so account/role administration (which is recorded without a
 * branch) is Super Admin-only reading.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'subject_type' => ['nullable', 'string', 'regex:/^[A-Za-z]{1,60}$/'],
            'actor_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = min((int) $request->query('per_page', 50), 100);
        $search = trim((string) $request->query('q', ''));

        $paginator = $this->scoped($request)
            ->when($request->query('action'), fn ($q, $action) => $q->where('action', $action))
            ->when($request->query('category'), fn ($q, $cat) => $q->whereRaw(Like::clause('action'), [Like::startsWith($cat.'.')]))
            ->when($request->query('actor_id'), fn ($q, $id) => $q->where('actor_id', $id))
            ->when($request->query('subject_type'), fn ($q, $type) => $q->where('subject_type', 'App\\Models\\'.$type))
            ->when($search !== '', function ($q) use ($search) {
                $like = Like::contains($search);
                $q->where(fn ($q) => $q->whereRaw(Like::clause('actor_name'), [$like])->orWhereRaw(Like::clause('action'), [$like]));
            })
            ->when($request->query('from'), function ($q, $from) use ($request) {
                $tz = DateRange::timezoneFor($request);
                $q->where('created_at', '>=', DateRange::bounds($from, $from, $tz)[0]);
            })
            ->when($request->query('to'), function ($q, $to) use ($request) {
                $tz = DateRange::timezoneFor($request);
                $q->where('created_at', '<=', DateRange::bounds($to, $to, $tz)[1]);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map($this->serialize(...))->values(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    /** Distinct action names the actor can see, for the filter dropdown. */
    public function actions(Request $request)
    {
        $actions = $this->scoped($request)->select('action')->distinct()->orderBy('action')->pluck('action');

        return response()->json(['data' => $actions->values()]);
    }

    private function scoped(Request $request)
    {
        $actor = $request->user();
        $branchId = $request->attributes->get('branch_id');
        $query = AuditLog::query();

        if (AdminAccess::isSuperAdmin($actor)) {
            return $query->when($request->query('branch_id'), fn ($q, $id) => $q->where('branch_id', $id));
        }

        // Everyone else: only their active branch; with no active branch, nothing.
        return $branchId ? $query->where('branch_id', $branchId) : $query->whereRaw('1 = 0');
    }

    private function serialize(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'createdAt' => $log->created_at,
            'actorId' => $log->actor_id,
            'actorName' => $log->actor_name,
            'action' => $log->action,
            'subjectType' => class_basename($log->subject_type),
            'subjectId' => $log->subject_id,
            'branchId' => $log->branch_id,
            'changes' => $log->changes,
            'ipAddress' => $log->ip_address,
        ];
    }
}
