<?php

namespace App\Services\Backup;

use App\Models\BackupRun;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class BackupService
{
    public function __construct(private DatabaseDumper $dumper) {}

    private function tmp(): string
    {
        $dir = storage_path('app/backup-tmp');
        is_dir($dir) || mkdir($dir, 0700, true);

        return $dir;
    }

    /** Dump DB (+ media) → zip → encrypt → store locally → copy off-site. Records the outcome either way. */
    public function run(string $trigger = 'schedule', ?string $userId = null): BackupRun
    {
        @set_time_limit(0);
        $run = BackupRun::create(['status' => 'running', 'trigger' => $trigger, 'created_by' => $userId, 'started_at' => now(), 'encrypted' => true]);
        $work = $this->tmp().'/'.$run->id;
        mkdir($work, 0700, true);

        try {
            $sql = $work.'/database.sql';
            $driver = $this->dumper->dump($sql);

            $zipPath = $work.'/backup.zip';
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
                throw new \RuntimeException('Could not create the archive.');
            }
            $zip->addFile($sql, 'database.sql');
            $manifest = ['createdAt' => now()->toIso8601String(), 'app' => config('app.name'), 'db' => $driver, 'files' => ['database.sql' => hash_file('sha256', $sql)], 'media' => []];

            $media = 0;
            if (config('backup.include_media')) {
                $disk = Storage::disk(config('backup.media_disk'));
                foreach ($disk->allFiles() as $f) {
                    $zip->addFromString('media/'.$f, (string) $disk->get($f));
                    $manifest['media'][$f] = hash('sha256', (string) $disk->get($f));
                    $media++;
                }
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
            $zip->close();

            $name = 'ct-'.now()->format('Ymd-His').'-'.substr($run->id, 0, 8).'.zip.enc';
            $enc = $work.'/'.$name;
            BackupCrypto::fromConfig()->encryptFile($zipPath, $enc);

            $local = Storage::disk(config('backup.disk'));
            $path = trim((string) config('backup.directory'), '/').'/'.$name;
            $in = fopen($enc, 'rb');
            $local->writeStream($path, $in);
            is_resource($in) && fclose($in);

            $run->fill(['filename' => $name, 'size_bytes' => filesize($enc), 'sha256' => hash_file('sha256', $enc), 'db_driver' => $driver, 'includes_media' => $media > 0]);

            $off = config('backup.offsite_disk');
            if ($off) {
                $run->offsite_disk = $off;
                try {
                    $s = fopen($enc, 'rb');
                    Storage::disk($off)->writeStream($path, $s);
                    is_resource($s) && fclose($s);
                    $run->offsite_ok = true;
                } catch (\Throwable $e) {
                    report($e);
                    $run->offsite_ok = false;
                    $run->error = Str::limit('Saved on the server but the off-site copy failed: '.$e->getMessage(), 480, '');
                }
            }
            $run->fill(['status' => 'ok', 'finished_at' => now()])->save();
        } catch (\Throwable $e) {
            report($e);
            $run->fill(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 480, ''), 'finished_at' => now()])->save();
        } finally {
            $this->rrmdir($work); // plaintext never lingers
        }

        return $run->fresh();
    }

    /** Decrypts and checks the archive end to end without touching the live database. Returns an error string or null. */
    public function verify(BackupRun $run): ?string
    {
        if ($run->status !== 'ok' || ! $run->filename) {
            return 'That backup did not complete.';
        }
        $work = $this->tmp().'/verify-'.Str::random(8);
        mkdir($work, 0700, true);
        try {
            $path = trim((string) config('backup.directory'), '/').'/'.$run->filename;
            $disk = Storage::disk(config('backup.disk'));
            if (! $disk->exists($path)) {
                return 'The backup file is missing from the server.';
            }
            $enc = $work.'/in.enc';
            $out = fopen($enc, 'wb');
            stream_copy_to_stream($disk->readStream($path), $out);
            fclose($out);
            if ($run->sha256 && ! hash_equals($run->sha256, hash_file('sha256', $enc))) {
                return 'The stored file no longer matches its checksum.';
            }
            $err = $this->extract($enc, $work.'/x');
            if ($err === null) {
                $run->forceFill(['verified_at' => now()])->save();
            }

            return $err;
        } finally {
            $this->rrmdir($work);
        }
    }

    /** Decrypt + unzip + check every file against the manifest into $dest. Returns an error string or null. */
    public function extract(string $encFile, string $dest): ?string
    {
        $zipPath = $dest.'.zip';
        try {
            BackupCrypto::fromConfig()->decryptFile($encFile, $zipPath);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return 'The decrypted archive is not a valid zip.';
        }
        is_dir($dest) || mkdir($dest, 0700, true);
        $zip->extractTo($dest);
        $zip->close();
        @unlink($zipPath);

        $m = json_decode((string) @file_get_contents($dest.'/manifest.json'), true);
        if (! is_array($m) || ! is_file($dest.'/database.sql')) {
            return 'The archive has no manifest or database.';
        }
        foreach (($m['files'] ?? []) as $f => $sum) {
            if (! is_file($dest.'/'.$f) || ! hash_equals($sum, hash_file('sha256', $dest.'/'.$f))) {
                return "Checksum mismatch for {$f}.";
            }
        }
        foreach (($m['media'] ?? []) as $f => $sum) {
            if (! is_file($dest.'/media/'.$f) || ! hash_equals($sum, hash_file('sha256', $dest.'/media/'.$f))) {
                return "Checksum mismatch for media/{$f}.";
            }
        }

        return null;
    }

    /** Grandfather-father-son: all runs of the last N days, one per week for M weeks, one per month for K months. */
    public function prune(): int
    {
        $r = config('backup.retention');
        $runs = BackupRun::where('status', 'ok')->orderByDesc('started_at')->get();
        $keep = [];
        $seen = ['w' => [], 'm' => []];
        foreach ($runs as $run) {
            $at = $run->started_at;
            $ageDays = $at->diffInDays(now());
            if ($ageDays < $r['daily']) {
                $keep[$run->id] = true;

                continue;
            }
            $w = $at->format('o-W');
            $m = $at->format('Y-m');
            if ($ageDays < $r['weekly'] * 7 && ! isset($seen['w'][$w])) {
                $seen['w'][$w] = $keep[$run->id] = true;

                continue;
            }
            if ($at->diffInMonths(now()) < $r['monthly'] && ! isset($seen['m'][$m])) {
                $seen['m'][$m] = $keep[$run->id] = true;
            }
        }
        // Never prune the newest good backup, whatever the policy says.
        if ($runs->isNotEmpty()) {
            $keep[$runs->first()->id] = true;
        }

        $removed = 0;
        foreach ($runs as $run) {
            if (isset($keep[$run->id])) {
                continue;
            }
            $path = trim((string) config('backup.directory'), '/').'/'.$run->filename;
            Storage::disk(config('backup.disk'))->delete($path);
            if ($run->offsite_disk && $run->offsite_ok) {
                try {
                    Storage::disk($run->offsite_disk)->delete($path);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
            $run->update(['status' => 'pruned']);
            $removed++;
        }
        BackupRun::where('status', 'failed')->where('created_at', '<', now()->subDays(30))->delete();

        return $removed;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
