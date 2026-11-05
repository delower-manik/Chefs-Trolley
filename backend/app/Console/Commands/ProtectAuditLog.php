<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProtectAuditLog extends Command
{
    protected $signature = 'audit:protect {--remove : drop the protection (needed before restoring the table or pruning old rows)}';

    protected $description = 'Make audit_logs append-only with database triggers (UPDATE and DELETE are refused)';

    public function handle(): int
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            $this->error("Not supported on {$driver}. Use REVOKE UPDATE, DELETE on audit_logs from the application's database user instead.");

            return self::FAILURE;
        }
        foreach (['update', 'delete'] as $op) {
            $name = "audit_logs_no_{$op}";
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            if ($this->option('remove')) {
                continue;
            }
            DB::unprepared(in_array($driver, ['mysql', 'mariadb'], true)
                ? "CREATE TRIGGER {$name} BEFORE ".strtoupper($op).' ON audit_logs FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'audit_logs is append-only\''
                : "CREATE TRIGGER {$name} BEFORE ".strtoupper($op)." ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only'); END");
        }
        $this->info($this->option('remove') ? 'Audit log protection removed.' : 'audit_logs is now append-only.');

        return self::SUCCESS;
    }
}
