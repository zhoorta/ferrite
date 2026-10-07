<?php

namespace App\Console\Commands;

use App\Models\Upload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('uploads:prune')]
#[Description('Delete unfinished uploads that have not received data recently')]
class PruneUploads extends Command
{
    public function handle(): int
    {
        $count = 0;

        Upload::query()
            ->where('updated_at', '<', now()->subHours(config('ferrite.upload_ttl_hours')))
            ->each(function (Upload $upload) use (&$count) {
                $upload->discard();
                $count++;
            });

        $this->info("Pruned {$count} unfinished upload(s).");

        return self::SUCCESS;
    }
}
