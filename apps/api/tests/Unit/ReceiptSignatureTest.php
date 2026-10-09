<?php

namespace Tests\Unit;

use App\Support\ReceiptService;
use Tests\TestCase;

final class ReceiptSignatureTest extends TestCase
{
    public function test_the_signature_uses_a_dedicated_key_and_a_canonical_amount(): void
    {
        $receipts = app(ReceiptService::class);

        $signature = $receipts->signature('company', '00000001', '10.10', 'USD');
        self::assertNotNull($signature);
        self::assertSame(20, strlen((string) $signature));
        self::assertSame($signature, $receipts->signature('company', '00000001', '10.1', 'USD'));
        self::assertNotSame($signature, $receipts->signature('company', '00000001', '10.11', 'USD'));

        // Sans clé QR dédiée, aucune signature : la clé de l'application n'est pas réutilisée.
        config(['security.receipts.qr_signing_secret' => '']);
        self::assertNull($receipts->signature('company', '00000001', '10.10', 'USD'));
    }
}
