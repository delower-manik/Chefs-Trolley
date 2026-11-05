<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class DecryptBackup extends Command
{
    protected $signature = 'backup:decrypt {file : path to a .zip.enc backup} {into : empty folder to unpack it into}';

    protected $description = 'Decrypt and unpack a backup so it can be restored (see the runbook in the README)';

    public function handle(BackupService $svc): int
    {
        $dest = (string) $this->argument('into');
        if (is_dir($dest) && count(scandir($dest)) > 2) {
            $this->error('That folder is not empty — choose a new one.');

            return self::FAILURE;
        }
        if ($err = $svc->extract((string) $this->argument('file'), $dest)) {
            $this->error($err);

            return self::FAILURE;
        }
        $this->info("Unpacked to {$dest}: database.sql, media/, manifest.json. Every checksum matched.");

        return self::SUCCESS;
    }
}
