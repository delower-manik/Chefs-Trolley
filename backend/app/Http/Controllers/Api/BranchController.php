<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveBranchRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Support\AdminAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Branch / outlet master data. A Super Admin sees and edits every branch;
 * anyone else with `branches.manage` only the branches they are assigned to,
 * and a branch they create is assigned to them automatically so it doesn't
 * vanish from their own list. There is deliberately no delete or
 * deactivate: branches own orders, stock, staff and ledgers, and nothing yet
 * knows how to retire one safely (see README).
 */
class BranchController extends Controller
{
    public function index(Request $request)
    {
        $branches = Branch::query()
            ->whereIn('id', AdminAccess::accessibleBranchIds($request->user()))
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $branches->map($this->serialize(...))->values()]);
    }

    public function store(SaveBranchRequest $request)
    {
        $actor = $request->user();

        $branch = DB::transaction(function () use ($request, $actor) {
            $branch = Branch::create($request->validated());
            $actor->branches()->syncWithoutDetaching([$branch->id]);
            $branch->defaultWarehouse(); // stock needs somewhere to live from day one

            return $branch;
        });

        $this->audit($request, 'branch.created', $branch, ['after' => $branch->only(['name', 'code', 'timezone'])]);

        return response()->json(['data' => $this->serialize($branch->loadCount('users'))], 201);
    }

    public function update(SaveBranchRequest $request, string $branch)
    {
        // Looked up through the actor's accessible set so another branch's id is a plain 404.
        $target = Branch::query()
            ->whereIn('id', AdminAccess::accessibleBranchIds($request->user()))
            ->findOrFail($branch);

        $fields = ['name', 'code', 'address', 'phone', 'timezone'];
        $before = $target->only($fields);
        $target->update($request->validated());

        $this->audit($request, 'branch.updated', $target, ['before' => $before, 'after' => $target->fresh()->only($fields)]);

        return response()->json(['data' => $this->serialize($target->fresh()->loadCount('users'))]);
    }

    private function audit(Request $request, string $action, Branch $branch, ?array $changes = null): void
    {
        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: $action,
            subjectType: Branch::class,
            subjectId: $branch->id,
            branchId: $branch->id,
            changes: $changes,
            ipAddress: $request->ip(),
        );
    }

    private function serialize(Branch $branch): array
    {
        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'code' => $branch->code,
            'address' => $branch->address,
            'phone' => $branch->phone,
            'timezone' => $branch->timezone,
            'isActive' => (bool) $branch->is_active,
            'userCount' => (int) ($branch->users_count ?? 0),
        ];
    }
}
