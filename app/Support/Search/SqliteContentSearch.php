<?php

namespace App\Support\Search;

use Illuminate\Database\Eloquent\Builder;

/**
 * SQLite: the FTS5 table `node_contents_fts` (see the migration). Every word is a prefix match and
 * all of them must be present; bm25 ranks, snippet() cuts and marks the text.
 */
class SqliteContentSearch implements ContentSearchDriver
{
    public function match(Builder $nodes, string $term): ?Builder
    {
        $words = SearchWords::of($term);

        if ($words === []) {
            return null;
        }

        $query = implode(' ', array_map(fn (string $word) => '"'.$word.'"*', $words));

        return $nodes
            ->join('node_contents as nc', 'nc.sha256', '=', 'nodes.sha256')
            ->join('node_contents_fts', 'node_contents_fts.rowid', '=', 'nc.id')
            ->whereRaw('node_contents_fts match ?', [$query])
            ->select('nodes.*')
            ->selectRaw("snippet(node_contents_fts, 0, char(1), char(2), '…', 18) as snippet")
            ->orderByRaw('bm25(node_contents_fts)');
    }

    public function present(string $snippet, string $term): string
    {
        return $snippet;
    }
}
