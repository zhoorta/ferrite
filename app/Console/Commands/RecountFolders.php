<?php

namespace App\Console\Commands;

use App\Support\FolderSizes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ferrite:folders:recount
    {--check : Only report folders whose stored size is wrong, change nothing (exit code 1 when any is)}
    {--user= : Only this user id}')]
#[Description('Recalculate the stored size of every folder from its files (docs/folder-sizes.md)')]
class RecountFolders extends Command
{
    public function handle(FolderSizes $sizes): int
    {
        $check = (bool) $this->option('check');
        $user = $this->option('user') === null ? null : (int) $this->option('user');

        $result = $sizes->recount(! $check, $user);

        if ($result['wrong'] === 0) {
            $this->info("Checked {$result['checked']} folders: all sizes are right.");

            return self::SUCCESS;
        }

        if ($check) {
            $this->error("Checked {$result['checked']} folders: {$result['wrong']} have the wrong size. Run without --check to fix them.");

            return self::FAILURE;
        }

        $this->info("Checked {$result['checked']} folders and corrected {$result['wrong']}.");

        return self::SUCCESS;
    }
}
