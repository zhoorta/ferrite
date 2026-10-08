<?php

namespace App\Console\Commands;

use App\Enums\NodeType;
use App\Jobs\ExtractContent;
use App\Models\Node;
use App\Models\NodeContent;
use App\Support\Search\ContentSearch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('search:index {--retry : Also try again the files whose extraction failed}')]
#[Description('Queue content extraction for stored files that are not indexed yet')]
class IndexContents extends Command
{
    public function handle(): int
    {
        if (! ContentSearch::enabled()) {
            $this->warn('Content search is off (FERRITE_SEARCH_CONTENTS) or the database does not support it.');

            return self::FAILURE;
        }

        if ($this->option('retry')) {
            $this->info('Reset '.NodeContent::query()->where('status', NodeContent::FAILED)->delete().' failed extractions.');
        }

        $queued = 0;

        // One file per distinct content: the first node found is enough, the text is shared.
        Node::query()
            ->where('type', NodeType::File)
            ->whereNotNull('sha256')
            ->whereNotNull('path')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('node_contents')->whereColumn('node_contents.sha256', 'nodes.sha256'))
            ->groupBy('sha256')
            ->selectRaw('min(id) as id')
            ->pluck('id')
            ->each(function ($id) use (&$queued) {
                ExtractContent::dispatch((int) $id);
                $queued++;
            });

        $this->info("Queued {$queued} files.");

        return self::SUCCESS;
    }
}
