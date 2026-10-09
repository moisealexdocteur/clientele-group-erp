<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_amounts_are_converted_to_cents_without_floating_point_drift(): void
    {
        self::assertSame(1010, Money::toCents('10.10'));
        self::assertSame(1010, Money::toCents(10.1));
        self::assertSame(1235, Money::toCents('12.345'));
        self::assertSame(-500, Money::toCents('-5.004'));
        self::assertSame(0, Money::toCents(null));
        self::assertSame('0.30', Money::fromCents(Money::toCents('0.10') + Money::toCents('0.20')));
        self::assertSame('-5.00', Money::fromCents(-500));
        self::assertSame('1000.00', Money::normalize('1000'));
    }

    public function test_an_invalid_amount_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::toCents('12,50');
    }
}
