<?php

namespace App\Support\Search;

use Illuminate\Database\Eloquent\Builder;

/**
 * MySQL and MariaDB: an InnoDB FULLTEXT index on `node_contents.text`, boolean mode with every word
 * required and matched as a prefix. InnoDB ignores words shorter than three characters and a list
 * of stopwords, and a required one of them would match nothing, so they are left out of the query.
 * There is no snippet function: a window of text around the first word is cut in SQL and the words
 * are marked in PHP.
 */
class MysqlContentSearch implements ContentSearchDriver
{
    private const MIN_LENGTH = 3;

    private const STOPWORDS = [
        'about', 'are', 'com', 'for', 'from', 'how', 'that', 'the', 'this', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www',
    ];

    public function match(Builder $nodes, string $term): ?Builder
    {
        $words = array_values(array_filter(
            SearchWords::of($term),
            fn (string $word) => mb_strlen($word) >= self::MIN_LENGTH && ! in_array($word, self::STOPWORDS, true),
        ));

        if ($words === []) {
            return null;
        }

        $query = implode(' ', array_map(fn (string $word) => '+'.$word.'*', $words));

        return $nodes
            ->join('node_contents as nc', 'nc.sha256', '=', 'nodes.sha256')
            ->whereRaw('match(nc.text) against (? in boolean mode)', [$query])
            ->select('nodes.*')
            ->selectRaw('substring(nc.text, greatest(locate(?, nc.text) - 60, 1), 240) as snippet', [$words[0]])
            ->selectRaw('match(nc.text) against (? in boolean mode) as content_rank', [$query])
            ->orderByDesc('content_rank');
    }

    public function present(string $snippet, string $term): string
    {
        $words = SearchWords::of($term);

        if ($words === []) {
            return $snippet;
        }

        $pattern = '/('.implode('|', array_map(fn (string $word) => preg_quote($word, '/'), $words)).')\p{L}*/iu';

        return '…'.(preg_replace($pattern, "\x01$0\x02", $snippet) ?? $snippet).'…';
    }
}
