<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Support\Str;
use PHPUnit\Framework\TestCase;

final class NeoFrameworkStrTest extends TestCase
{
    public function testPureTextUtilitiesAreAvailableOutsideTheLegacyFacade(): void
    {
        self::assertSame('11987654321', Str::onlyNumbers('(11) 98765-4321'));
        self::assertSame("Ol\u{00e1}", Str::decodeUnicodeUrl('Ol%C3%A1'));
        self::assertSame('A', Str::decodeUnicodeUrl('%u0041'));
        self::assertTrue(Str::isBase64('c2VjcmV0'));
        self::assertFalse(Str::isBase64('not base64!'));
    }

    public function testSlugUsesUnicodeTransliterationAndStableSeparators(): void
    {
        self::assertSame('acao-cafe', Str::slug('Ação & Café'));
        self::assertTrue(Str::isBase64('YQ=='));
        self::assertFalse(Str::isBase64('YQ==='));
    }
}
