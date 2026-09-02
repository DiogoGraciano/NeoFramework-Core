<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Middleware\Cors;
use NeoFramework\Core\Request;
use NeoFramework\Core\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class NeoFrameworkCorsTest extends TestCase
{
    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface { return (new Response())->text('ok'); }
        };
    }

    public function testAllowedOriginIsAppliedAroundResponse(): void
    {
        $cors = new Cors(['allowed_origins' => ['https://app.example']]);
        $response = $cors->process(new Request('GET', '/', ['Origin' => 'https://app.example']), $this->handler());
        self::assertSame('https://app.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testPreflightStopsAtMiddlewareWith204(): void
    {
        $response = (new Cors())->process(new Request('OPTIONS', '/', ['Origin' => 'https://app.example']), $this->handler());
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    public function testCredentialsWithWildcardIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cors(['allowed_origins' => ['*'], 'allow_credentials' => true]);
    }
}
