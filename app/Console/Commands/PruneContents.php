<?php

namespace App\Console\Commands;

use App\Models\NodeContent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('search:prune')]
#[Description('Delete extracted text of contents that no file uses any more')]
class PruneContents extends Command
{
    public function handle(): int
    {
        $deleted = NodeContent::query()
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('nodes')->whereColumn('nodes.sha256', 'node_contents.sha256'))
            ->delete();

        $this->info("Deleted {$deleted} unused entries.");

        return self::SUCCESS;
    }
}
