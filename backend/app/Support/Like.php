<?php

namespace App\Support;

/**
 * Safe, portable substring search. User-typed `%` and `_` are escaped so they
 * match literally, and the escape character is `!` with an explicit ESCAPE
 * clause -- a backslash escape is the default on MySQL/PostgreSQL but not on
 * SQLite, so relying on it would behave differently per database. Both sides
 * are lower-cased because PostgreSQL's LIKE is case-sensitive.
 *
 * Usage: ->whereRaw(Like::clause('name'), [Like::contains($term)])
 */
class Like
{
    public static function contains(string $term): string
    {
        return '%'.self::escape(mb_strtolower($term)).'%';
    }

    public static function startsWith(string $term): string
    {
        return self::escape(mb_strtolower($term)).'%';
    }

    public static function clause(string $column): string
    {
        return "LOWER({$column}) LIKE ? ESCAPE '!'";
    }

    private static function escape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
