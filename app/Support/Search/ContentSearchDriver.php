<?php

namespace App\Support\Search;

use App\Models\Node;
use Illuminate\Database\Eloquent\Builder;

/**
 * The database-specific half of content search. Everything else (extraction, who may see what,
 * paths) is shared. Snippets use \x01 and \x02 around the matched words; see SearchSnippet.
 */
interface ContentSearchDriver
{
    /**
     * Narrow $nodes (which must select from `nodes`) to files whose extracted text matches $term, best
     * first, with a `snippet` column. Null when the term has nothing searchable in it.
     *
     * @param  Builder<Node>  $nodes
     * @return Builder<Node>|null
     */
    public function match(Builder $nodes, string $term): ?Builder;

    /**
     * The `snippet` column of a result, with the matched words marked.
     */
    public function present(string $snippet, string $term): string;
}
