<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Middleware\SecurityHeaders;
use NeoFramework\Core\Request;
use NeoFramework\Core\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class NeoFrameworkSecurityHeadersTest extends TestCase
{
    public function testHeadersWrapPsrResponse(): void
    {
        $_SERVER['HTTPS'] = 'off';
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface { return new Response(); }
        };
        $response = (new SecurityHeaders())->process(new Request(), $handler);
        self::assertSame('nosniff', $response->getHeaderLine('x-content-type-options'));
        self::assertSame('SAMEORIGIN', $response->getHeaderLine('x-frame-options'));
        self::assertFalse($response->hasHeader('strict-transport-security'));
    }
}
