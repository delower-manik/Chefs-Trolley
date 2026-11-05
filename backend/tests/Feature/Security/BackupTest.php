<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\Branch;
use App\Services\Backup\BackupCrypto;
use App\Services\Backup\BackupService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('offsite');
        config(['backup.disk' => 'local', 'backup.media_disk' => 'public']);
    }

    private function tmpFile(string $content): string
    {
        $p = tempnam(sys_get_temp_dir(), 'ctt');
        file_put_contents($p, $content);

        return $p;
    }

    // ---- encryption ----

    public function test_encryption_round_trips_large_files_and_hides_the_content(): void
    {
        $plain = random_bytes(2_500_000).'SECRET-MARKER';
        $in = $this->tmpFile($plain);
        $enc = $in.'.enc';
        $out = $in.'.out';
        $c = new BackupCrypto('a-test-secret');
        $c->encryptFile($in, $enc);

        $this->assertStringNotContainsString('SECRET-MARKER', file_get_contents($enc));
        $c->decryptFile($enc, $out);
        $this->assertSame(hash_file('sha256', $in), hash_file('sha256', $out));

        $empty = $this->tmpFile('');
        $c->encryptFile($empty, $empty.'.enc');
        $c->decryptFile($empty.'.enc', $empty.'.out');
        $this->assertSame('', file_get_contents($empty.'.out'));
    }

    public function test_wrong_key_tampering_truncation_and_reordering_are_all_detected(): void
    {
        $in = $this->tmpFile(random_bytes(2_300_000));
        $enc = $in.'.enc';
        (new BackupCrypto('right-key'))->encryptFile($in, $enc);
        $good = file_get_contents($enc);

        $try = function (string $bytes, string $key = 'right-key') use ($in) {
            $f = $in.'.t';
            file_put_contents($f, $bytes);
            try {
                (new BackupCrypto($key))->decryptFile($f, $in.'.o');

                return null;
            } catch (\RuntimeException $e) {
                return $e->getMessage();
            }
        };

        $this->assertNull($try($good));
        $this->assertNotNull($try($good, 'wrong-key'));
        $flipped = $good;
        $flipped[strlen($good) - 40] = chr(ord($good[strlen($good) - 40]) ^ 1);
        $this->assertNotNull($try($flipped), 'a flipped bit must fail');
        $this->assertNotNull($try(substr($good, 0, 30 + 4 + 1048576 + 16)), 'dropping the later chunks must fail');
        $this->assertNotNull($try(substr($good, 0, -10)), 'a cut-short file must fail');
        $this->assertNotNull($try('not a backup at all, definitely'));
    }

    // ---- a real backup run ----

    public function test_a_backup_contains_the_database_and_media_is_encrypted_and_verifies(): void
    {
        $branch = Branch::create(['name' => 'Gulshan', 'code' => 'GUL1']);
        Storage::disk('public')->put('menu/pizza.jpg', 'IMAGE-BYTES');

        $run = app(BackupService::class)->run('manual');
        $this->assertSame('ok', $run->status, (string) $run->error);
        $this->assertTrue($run->includes_media);
        $file = 'backups/'.$run->filename;
        Storage::disk('local')->assertExists($file);
        $this->assertStringNotContainsString('Gulshan', Storage::disk('local')->get($file));
        $this->assertSame($run->sha256, hash('sha256', Storage::disk('local')->get($file)));
        $this->assertEmpty(glob(storage_path('app/backup-tmp/*')), 'no plaintext may be left behind');

        // Decrypt it back and look inside — this is the restore path.
        $dest = sys_get_temp_dir().'/ct-restore-'.uniqid();
        $enc = $this->tmpFile(Storage::disk('local')->get($file));
        $this->assertNull(app(BackupService::class)->extract($enc, $dest));
        $sql = file_get_contents($dest.'/database.sql');
        $this->assertStringContainsString('Gulshan', $sql);
        $this->assertStringContainsString('GUL1', $sql);
        $this->assertStringNotContainsString('personal_access_tokens', $sql);
        $this->assertSame('IMAGE-BYTES', file_get_contents($dest.'/media/menu/pizza.jpg'));

        $this->assertNull(app(BackupService::class)->verify($run));
        $this->assertNotNull($run->fresh()->verified_at);

        Storage::disk('local')->put($file, 'corrupted');
        $this->assertNotNull(app(BackupService::class)->verify($run));
    }

    public function test_the_sql_dump_can_rebuild_the_data_in_a_fresh_database(): void
    {
        Branch::create(['name' => "O'Brien's Café, ঢাকা", 'code' => 'OBR1']);
        $run = app(BackupService::class)->run('manual');
        $dest = sys_get_temp_dir().'/ct-restore-'.uniqid();
        app(BackupService::class)->extract($this->tmpFile(Storage::disk('local')->get('backups/'.$run->filename)), $dest);

        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec(file_get_contents($dest.'/database.sql'));
        $row = $pdo->query("select name, code from branches where code = 'OBR1'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame("O'Brien's Café, ঢাকা", $row['name']);
    }

    public function test_offsite_copy_is_made_and_a_failing_offsite_is_reported_not_hidden(): void
    {
        config(['backup.offsite_disk' => 'offsite']);
        $run = app(BackupService::class)->run('schedule');
        $this->assertTrue($run->offsite_ok);
        Storage::disk('offsite')->assertExists('backups/'.$run->filename);

        config(['backup.offsite_disk' => 'nonexistent-disk']);
        $bad = app(BackupService::class)->run('schedule');
        $this->assertSame('ok', $bad->status);          // the local copy still exists
        $this->assertFalse($bad->offsite_ok);
        $this->assertStringContainsString('off-site', $bad->error);
        $this->artisan('backup:run')->assertFailed();    // but the command (and so the cron mail) says it failed
    }

    public function test_a_failed_backup_is_recorded_with_the_reason(): void
    {
        config(['backup.key' => '', 'app.key' => '']);
        $run = app(BackupService::class)->run('manual');
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('encryption key', $run->error);
    }

    // ---- retention ----

    public function test_retention_keeps_recent_then_weekly_then_monthly_and_always_the_newest(): void
    {
        config(['backup.retention' => ['daily' => 3, 'weekly' => 2, 'monthly' => 2]]);
        $mk = fn (int $daysAgo) => BackupRun::create(['status' => 'ok', 'filename' => "f{$daysAgo}.enc", 'started_at' => now()->subDays($daysAgo)]);
        $runs = collect([0, 1, 2, 4, 5, 9, 10, 40, 41, 200])->mapWithKeys(fn ($d) => [$d => $mk($d)]);
        foreach ($runs as $r) {
            Storage::disk('local')->put('backups/'.$r->filename, 'x');
        }

        $removed = app(BackupService::class)->prune();
        $status = fn ($d) => $runs[$d]->fresh()->status;

        foreach ([0, 1, 2] as $d) {
            $this->assertSame('ok', $status($d), "day {$d} is inside the daily window");
        }
        $this->assertSame('pruned', $status(200)); // older than the monthly window
        $this->assertGreaterThan(0, $removed);
        Storage::disk('local')->assertMissing('backups/f200.enc');
        Storage::disk('local')->assertExists('backups/f0.enc');
        $this->assertSame(1, $runs->filter(fn ($r, $d) => $d >= 3 && $d <= 10 && $status($d) === 'ok')->count() >= 1 ? 1 : 0);
    }

    // ---- the API ----

    public function test_backup_endpoints_need_the_permission_and_are_audited(): void
    {
        $this->actingAsUserWithPermissions(['orders.view']);
        $this->getJson('/api/v1/backups')->assertStatus(403);

        $this->actingAsUserWithPermissions(['backups.manage'], Branch::first());
        $run = $this->postJson('/api/v1/backups')->assertStatus(201)->assertJsonPath('data.status', 'ok')->json('data');
        $this->getJson('/api/v1/backups')->assertOk()->assertJsonPath('data.0.id', $run['id'])->assertJsonPath('meta.keyConfigured', true);
        $this->postJson("/api/v1/backups/{$run['id']}/verify")->assertOk()->assertJsonPath('data.ok', true);
        $this->get("/api/v1/backups/{$run['id']}/download")->assertOk();
        foreach (['backup.run', 'backup.verify', 'backup.download'] as $a) {
            $this->assertDatabaseHas('audit_logs', ['action' => $a]);
        }
    }

    public function test_decrypt_command_unpacks_and_refuses_a_non_empty_folder(): void
    {
        $run = app(BackupService::class)->run('manual');
        $enc = $this->tmpFile(Storage::disk('local')->get('backups/'.$run->filename));
        $dest = sys_get_temp_dir().'/ct-cmd-'.uniqid();
        $this->artisan('backup:decrypt', ['file' => $enc, 'into' => $dest])->assertSuccessful();
        $this->assertFileExists($dest.'/database.sql');
        $this->artisan('backup:decrypt', ['file' => $enc, 'into' => $dest])->assertFailed();
    }

    // ---- audit log protection ----

    public function test_audit_logs_can_be_made_append_only(): void
    {
        $branch = Branch::create(['name' => 'B', 'code' => 'B1']);
        $log = AuditLog::record(null, null, 'test.event', Branch::class, $branch->id);

        $this->artisan('audit:protect')->assertSuccessful();
        $this->expectException(QueryException::class);
        try {
            \DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered']);
        } finally {
            $this->assertSame('test.event', \DB::table('audit_logs')->where('id', $log->id)->value('action'));
        }
    }

    public function test_audit_delete_is_refused_and_protection_can_be_lifted(): void
    {
        $branch = Branch::create(['name' => 'B', 'code' => 'B2']);
        $log = AuditLog::record(null, null, 'test.event', Branch::class, $branch->id);
        $this->artisan('audit:protect')->assertSuccessful();
        try {
            \DB::table('audit_logs')->where('id', $log->id)->delete();
            $this->fail('delete should have been refused');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        $this->artisan('audit:protect', ['--remove' => true])->assertSuccessful();
        $this->assertSame(1, \DB::table('audit_logs')->where('id', $log->id)->delete());
    }
}
