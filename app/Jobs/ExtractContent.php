<?php

namespace App\Jobs;

use App\Models\Node;
use App\Models\NodeContent;
use App\Support\ContentExtractor;
use App\Support\FileKind;
use App\Support\Search\ContentSearch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads the text of a stored file into the content search index, once per distinct content.
 * Runs on the `search` queue, which the default worker serves after its own queue, so it never
 * holds up anything else. A failure is recorded on the content row (`search:index --retry` tries
 * again) and never breaks the upload that triggered it.
 */
class ExtractContent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Seconds; a PDF on a remote disk is copied first. */
    public int $timeout = 600;

    public function __construct(public int $nodeId)
    {
        $this->onQueue('search');
    }

    /**
     * Queue the extraction for a file node, unless content search is off or its content is already known.
     */
    public static function queueFor(Node $node): void
    {
        if (! ContentSearch::enabled() || ! $node->isFile() || $node->sha256 === null || $node->path === null) {
            return;
        }

        if (NodeContent::query()->where('sha256', $node->sha256)->exists()) {
            return;
        }

        // Without a queue worker the job would run inside the upload request, and a PDF can take long
        // enough to hit a time limit. The scheduled `search:index` picks it up instead.
        if (config('queue.default') === 'sync' && FileKind::of($node->mime) === 'pdf') {
            return;
        }

        try {
            self::dispatch($node->id);
        } catch (Throwable $e) {
            // Without a queue the file simply stays unindexed; `search:index` picks it up later.
            report($e);
        }
    }

    public function handle(ContentExtractor $extractor): void
    {
        $node = Node::query()->find($this->nodeId);

        if ($node === null || ! $node->isFile() || $node->sha256 === null || ! ContentSearch::enabled()) {
            return;
        }

        $known = NodeContent::query()->where('sha256', $node->sha256)->first();

        if ($known !== null && $known->status !== NodeContent::FAILED) {
            return;
        }

        try {
            $extractor->extract($node);
        } catch (Throwable $e) {
            report($e);

            NodeContent::query()->updateOrCreate(
                ['sha256' => $node->sha256],
                ['status' => NodeContent::FAILED, 'reason' => Str::limit($e->getMessage(), 90, ''), 'text' => null, 'extracted_at' => now()],
            );
        }
    }
}
