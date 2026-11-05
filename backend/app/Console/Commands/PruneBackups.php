<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class PruneBackups extends Command
{
    protected $signature = 'backup:prune';

    protected $description = 'Delete old backups per the retention policy (daily / weekly / monthly), always keeping the newest';

    public function handle(BackupService $svc): int
    {
        $this->info('Removed '.$svc->prune().' old backup(s).');

        return self::SUCCESS;
    }
}
