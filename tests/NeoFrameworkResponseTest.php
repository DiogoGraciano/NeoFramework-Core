<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class NeoFrameworkResponseTest extends TestCase
{
    public function testResponseImplementsPsr7Immutability(): void
    {
        $response = new Response();
        $changed = $response->withStatus(201)->withHeader('X-Test', 'yes');
        self::assertInstanceOf(ResponseInterface::class, $changed);
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-Test'));
        self::assertSame(201, $changed->getStatusCode());
    }

    public function testResponseHelpers(): void
    {
        $json = (new Response())->json(['ok' => true], 201);
        self::assertSame(201, $json->getStatusCode());
        self::assertSame('application/json; charset=UTF-8', $json->getHeaderLine('Content-Type'));
        self::assertSame('{"ok":true}', $json->getContent());

        $html = (new Response())->html('<h1>Neo</h1>');
        self::assertSame('<h1>Neo</h1>', $html->getContent());
    }

    public function testRedirectsRejectUnsafeSchemes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Response())->goToSite('javascript:alert(1)');
    }

    public function testCookiesAreRepresentedAsImmutableHeaders(): void
    {
        $response = new Response();
        $changed = $response->withCookie('session', 'abc', null, '/', null, true);
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertStringContainsString('session=abc', $changed->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('Secure', $changed->getHeaderLine('Set-Cookie'));
    }
}
