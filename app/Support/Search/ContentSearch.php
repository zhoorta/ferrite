<?php

namespace App\Support\Search;

use Illuminate\Support\Facades\DB;

class ContentSearch
{
    /**
     * Turned on in the configuration and supported by the database (SQLite, MySQL, MariaDB).
     */
    public static function enabled(): bool
    {
        return (bool) config('ferrite.search_contents') && self::driver() !== null;
    }

    public static function driver(): ?ContentSearchDriver
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => new SqliteContentSearch,
            'mysql', 'mariadb' => new MysqlContentSearch,
            default => null,
        };
    }

    /**
     * A snippet as HTML: escaped, with the matched words in <mark>.
     */
    public static function html(string $snippet): string
    {
        return str_replace(["\x01", "\x02"], ['<mark>', '</mark>'], e($snippet));
    }
}
