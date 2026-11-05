<?php

namespace Tests\Unit;

use App\Support\Like;
use PHPUnit\Framework\TestCase;

class LikeTest extends TestCase
{
    public function test_contains_wraps_and_lowercases_the_term(): void
    {
        $this->assertSame('%burger%', Like::contains('BuRgEr'));
    }

    public function test_wildcards_typed_by_the_user_are_escaped_so_they_match_literally(): void
    {
        $this->assertSame('%50!% off%', Like::contains('50% off'));
        $this->assertSame('%a!_b%', Like::contains('a_b'));
    }

    public function test_the_escape_character_itself_is_escaped_first(): void
    {
        $this->assertSame('%wow!!%', Like::contains('wow!'));
        // "!%" typed by the user must not turn into an escaped percent sign
        $this->assertSame('%!!!%%', Like::contains('!%'));
    }

    public function test_starts_with_only_appends_the_wildcard(): void
    {
        $this->assertSame('order.%', Like::startsWith('ORDER.'));
    }

    public function test_clause_names_the_escape_character_explicitly(): void
    {
        $this->assertSame("LOWER(name) LIKE ? ESCAPE '!'", Like::clause('name'));
    }

    public function test_multibyte_terms_are_lowercased_without_corruption(): void
    {
        $this->assertSame('%ঢাকা%', Like::contains('ঢাকা'));
        $this->assertSame('%élan%', Like::contains('ÉLAN'));
    }
}
