<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_to_minor_is_exact_for_two_decimal_inputs(): void
    {
        $this->assertSame(10, Money::toMinor(0.1));
        $this->assertSame(30, Money::toMinor(0.1 + 0.2)); // the classic float trap
        $this->assertSame(110, Money::toMinor('1.10'));
        $this->assertSame(1999, Money::toMinor(19.99));
        $this->assertSame(0, Money::toMinor(null));
        $this->assertSame(0, Money::toMinor(''));
        $this->assertSame(-250, Money::toMinor(-2.5));
    }

    public function test_to_decimal_formats_two_places(): void
    {
        $this->assertSame('0.05', Money::toDecimal(5));
        $this->assertSame('12.00', Money::toDecimal(1200));
        $this->assertSame('-3.07', Money::toDecimal(-307));
        $this->assertSame(12.34, Money::toFloat(1234));
    }

    public function test_percent_rounds_half_up(): void
    {
        $this->assertSame(1, Money::percent(10, 5));      // 0.5 paisa -> 1
        $this->assertSame(0, Money::percent(9, 5));       // 0.45 -> 0
        $this->assertSame(750, Money::percent(10000, 7.5)); // 7.5% of 100.00
        $this->assertSame(1500, Money::percent(10000, '15.00'));
        $this->assertSame(0, Money::percent(10000, 0));
    }

    public function test_inclusive_tax_is_the_portion_inside_the_gross(): void
    {
        // 115.00 gross at 15% -> 15.00 tax, 100.00 net
        $this->assertSame(1500, Money::inclusiveTax(11500, 15));
        $this->assertSame(0, Money::inclusiveTax(11500, 0));
        // 100.00 gross at 5% -> 4.76
        $this->assertSame(476, Money::inclusiveTax(10000, 5));
    }

    public function test_allocate_always_sums_to_the_total(): void
    {
        $parts = Money::allocate(10000, ['a' => 3300, 'b' => 3300, 'c' => 3400]);
        $this->assertSame(10000, array_sum($parts));
        $this->assertSame(['a', 'b', 'c'], array_keys($parts));

        $odd = Money::allocate(100, [1, 1, 1]);
        $this->assertSame(100, array_sum($odd));
        $this->assertSame([34, 33, 33], array_values($odd));

        $this->assertSame(500, array_sum(Money::allocate(500, [0, 0])), 'zero weights spread evenly');
        $this->assertSame([], Money::allocate(100, []));
    }

    public function test_negative_rate_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::percent(100, -1);
    }
}
