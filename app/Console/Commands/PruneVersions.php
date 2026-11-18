<?php

namespace App\Console\Commands;

use App\Support\VersionKeeper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('versions:prune')]
#[Description('Drop file versions over FERRITE_VERSIONS_KEEP per file or older than FERRITE_VERSIONS_DAYS, and give the space back')]
class PruneVersions extends Command
{
    public function handle(VersionKeeper $versions): int
    {
        $this->info('Removed '.$versions->pruneAll().' versions.');

        return self::SUCCESS;
    }
}
