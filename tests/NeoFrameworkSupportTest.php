<?php
declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use NeoFramework\Core\Support\ConsoleOutput;
use NeoFramework\Core\Support\Id;
use NeoFramework\Core\Support\Money;
use NeoFramework\Core\Support\Path;
use NeoFramework\Core\Support\ProjectRoot;
use NeoFramework\Core\Support\Str;
use NeoFramework\Core\Support\UserAgent;
use PHPUnit\Framework\TestCase;

final class NeoFrameworkSupportTest extends TestCase
{
    public function testMoneyStoresMinorUnitsWithoutFloatingPointArithmetic(): void
    {
        $amount = Money::fromDecimal('12.50', 'brl')->add(Money::ofMinor(25, 'BRL'));

        self::assertSame(1275, $amount->minorAmount);
        self::assertSame('BRL', $amount->currency);
        self::assertSame(2, $amount->fractionDigits);
        self::assertStringContainsString('12,75', $amount->format());
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimal('12.501', 'BRL');
    }

    public function testPathIdAndUserAgentHelpersHaveExplicitContracts(): void
    {
        self::assertSame('storage' . DIRECTORY_SEPARATOR . 'photos' . DIRECTORY_SEPARATOR . 'avatar.png', Path::normalizeRelative('storage/./photos/avatar.png'));
        self::assertSame(24, strlen(Id::randomHex(12)));
        self::assertTrue(UserAgent::isMobile('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)'));
        self::assertFalse(UserAgent::isMobile('Mozilla/5.0 (X11; Linux x86_64)'));

        $this->expectException(InvalidArgumentException::class);
        Path::normalizeRelative('../secrets');
    }

    public function testSupportValueObjectsRejectAmbiguousInputAndNormalizeText(): void
    {
        self::assertSame('a' . DIRECTORY_SEPARATOR . 'c', Path::normalizeRelative('a/b/../c'));
        self::assertSame('Olá', Str::decodeUnicodeUrl('Ol%u00E1'));
        self::assertSame('11987654321', Str::onlyNumbers('(11) 98765-4321'));
        self::assertTrue(Str::isBase64(base64_encode('coverage')));
        self::assertFalse(Str::isBase64('not base64!'));

        ProjectRoot::set('/tmp/neof-root');
        self::assertSame('/tmp/neof-root' . DIRECTORY_SEPARATOR, ProjectRoot::path());
        ProjectRoot::set(null);

        try {
            Money::ofMinor(1, 'reais');
            self::fail('Uma moeda fora de ISO 4217 deve ser rejeitada.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        Money::ofMinor(1, 'BRL')->add(Money::ofMinor(1, 'USD'));
    }

    public function testIdentifiersAndConsoleOutputMakeInvalidAndQuietModesExplicit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Id::randomHex(0);
    }

    public function testConsoleOutputWritesOperationalMessagesAndHonoursQuietMode(): void
    {
        ob_start();
        $output = new ConsoleOutput();
        $output->write('normal');
        $output->success('success');
        $output->warning('warning');
        $visible = (string) ob_get_clean();

        self::assertStringContainsString('normal', $visible);
        self::assertStringContainsString('success', $visible);
        self::assertStringContainsString('warning', $visible);

        ob_start();
        (new ConsoleOutput(quiet: true))->write('silencioso');
        self::assertSame('', (string) ob_get_clean());
    }
}
