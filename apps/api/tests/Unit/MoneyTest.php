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
        self::assertSame(1010, Money::toCents('10.1'));
        self::assertSame('7.56', Money::convert('1000', 'HTG', 'USD', '132.3456'));
        self::assertSame('13125.00', Money::convert('100', 'USD', 'HTG', '131.25'));
        self::assertSame('1 250,50', Money::formatFr('1250.5'));
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
