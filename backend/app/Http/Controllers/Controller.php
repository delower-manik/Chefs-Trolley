<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    /** Writes an audit-log row for an action on $subject by the current user. */
    protected function recordAudit(Request $request, string $action, Model $subject, ?array $changes = null): void
    {
        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: $action,
            subjectType: $subject::class,
            subjectId: $subject->getKey(),
            branchId: $subject->getAttribute('branch_id'),
            changes: $changes,
            ipAddress: $request->ip(),
        );
    }

    /** The active branch, or a ready-made 422 response when none is selected. */
    protected function requireBranch(Request $request): string|JsonResponse
    {
        return $request->attributes->get('branch_id') ?: response()->json([
            'code' => 'branch_required',
            'message' => 'Select an active branch first.',
        ], 422);
    }
}
