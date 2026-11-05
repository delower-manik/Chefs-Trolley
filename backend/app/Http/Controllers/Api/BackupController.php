<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BackupRun;
use App\Services\Backup\BackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BackupController extends Controller
{
    public function __construct(private BackupService $backups) {}

    public function index()
    {
        $runs = BackupRun::orderByDesc('started_at')->limit(60)->get()->map(fn ($r) => $this->serialize($r));
        $last = BackupRun::where('status', 'ok')->latest('started_at')->first();

        return response()->json([
            'data' => $runs,
            'meta' => [
                'lastOkAt' => $last?->started_at,
                'keyConfigured' => (bool) config('backup.key'), // false = falling back to APP_KEY: set a dedicated key and store a copy elsewhere
                'offsiteDisk' => config('backup.offsite_disk'),
                'includeMedia' => (bool) config('backup.include_media'),
                'retention' => config('backup.retention'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $run = $this->backups->run('manual', $request->user()->id);
        $this->recordAudit($request, 'backup.run', $run, ['status' => $run->status]);

        return response()->json(['data' => $this->serialize($run)], $run->status === 'ok' ? 201 : 500);
    }

    public function verify(Request $request, BackupRun $backup)
    {
        $err = $this->backups->verify($backup);
        $this->recordAudit($request, 'backup.verify', $backup, ['ok' => $err === null]);

        return response()->json(['data' => ['ok' => $err === null, 'message' => $err ?? 'Decrypted and every checksum matched.', 'backup' => $this->serialize($backup->fresh())]]);
    }

    public function download(Request $request, BackupRun $backup)
    {
        abort_unless($backup->status === 'ok' && $backup->filename, 404);
        $path = trim((string) config('backup.directory'), '/').'/'.$backup->filename;
        abort_unless(Storage::disk(config('backup.disk'))->exists($path), 404);
        $this->recordAudit($request, 'backup.download', $backup);

        return Storage::disk(config('backup.disk'))->download($path, $backup->filename);
    }

    private function serialize(BackupRun $r): array
    {
        return [
            'id' => $r->id, 'status' => $r->status, 'trigger' => $r->trigger, 'filename' => $r->filename, 'sizeBytes' => $r->size_bytes,
            'database' => $r->db_driver, 'includesMedia' => $r->includes_media, 'offsiteDisk' => $r->offsite_disk, 'offsiteOk' => $r->offsite_ok,
            'error' => $r->error, 'verifiedAt' => $r->verified_at, 'startedAt' => $r->started_at, 'finishedAt' => $r->finished_at,
        ];
    }
}
