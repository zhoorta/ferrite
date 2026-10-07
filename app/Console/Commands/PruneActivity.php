<?php

namespace App\Console\Commands;

use App\Models\Activity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('activity:prune')]
#[Description('Delete activity log entries older than the retention period')]
class PruneActivity extends Command
{
    public function handle(): int
    {
        $count = Activity::query()->where('created_at', '<', now()->subDays(config('ferrite.activity_days')))->delete();

        $this->info("Pruned {$count} activity entr".($count === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
