<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\Marketing\CampaignSender;
use Illuminate\Console\Command;

class DispatchCampaigns extends Command
{
    protected $signature = 'campaigns:dispatch';

    protected $description = 'Send scheduled campaigns that are due';

    public function handle(CampaignSender $sender): int
    {
        Campaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->limit(20)->get()
            ->each(function (Campaign $c) use ($sender) {
                try {
                    $sender->send($c);
                } catch (\Throwable $e) {
                    // Don't leave it stuck as "sending" forever; a person can see it failed and re-create it.
                    report($e);
                    $c->update(['status' => 'cancelled']);
                }
            });

        return self::SUCCESS;
    }
}
