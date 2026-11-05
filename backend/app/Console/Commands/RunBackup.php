<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class RunBackup extends Command
{
    protected $signature = 'backup:run {--verify : Decrypt and check the archive right after it is written}';

    protected $description = 'Back up the database (and uploaded media) into one encrypted archive';

    public function handle(BackupService $svc): int
    {
        $run = $svc->run('schedule');
        if ($run->status !== 'ok') {
            $this->error('Backup FAILED: '.$run->error);

            return self::FAILURE;
        }
        $this->info("Backup written: {$run->filename} (".number_format($run->size_bytes / 1024).' KB)'.($run->offsite_disk ? ($run->offsite_ok ? ", copied to {$run->offsite_disk}" : ', OFF-SITE COPY FAILED: '.$run->error) : ', no off-site disk configured'));
        if ($this->option('verify') && ($err = $svc->verify($run))) {
            $this->error('Verification FAILED: '.$err);

            return self::FAILURE;
        }

        return $run->offsite_ok === false ? self::FAILURE : self::SUCCESS;
    }
}
