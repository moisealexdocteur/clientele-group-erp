<?php

namespace Tests\Unit;

use App\Support\ReceiptSecretGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReceiptSecretGuardTest extends TestCase
{
    public function test_the_application_refuses_to_boot_without_a_receipt_secret(): void
    {
        $this->expectException(RuntimeException::class);
        ReceiptSecretGuard::check('production', '');
    }

    public function test_a_short_secret_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        ReceiptSecretGuard::check('preprod', str_repeat('a', 31));
    }

    public function test_a_long_secret_or_the_image_build_is_accepted(): void
    {
        ReceiptSecretGuard::check('production', str_repeat('a', 32));
        ReceiptSecretGuard::check('production', '', 'package:discover');
        ReceiptSecretGuard::check('testing', '');

        $this->addToAssertionCount(3);
    }
}
