<?php

namespace App\Support\Search;

class SearchWords
{
    private const MAX = 8;

    /**
     * The words of a search term: letters and digits only, lower case, so nothing a person types
     * can be read as search syntax by the database.
     *
     * @return list<string>
     */
    public static function of(string $term): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($term), $found);

        return array_slice(array_values(array_unique($found[0])), 0, self::MAX);
    }
}
