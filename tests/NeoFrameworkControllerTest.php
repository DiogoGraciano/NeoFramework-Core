<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Request;
use NeoFramework\Core\Response;
use PHPUnit\Framework\TestCase;

final class ControllerFixture extends Controller
{
    public function pageValue(): int { return $this->page(); }
    public function offset(int $limit): int { return $this->getOffset($limit); }
    public function makeJson(): Response { return $this->json(['ok' => true], 201); }
}

final class NeoFrameworkControllerTest extends TestCase
{
    public function testRequestAndResponseCanBeInjected(): void
    {
        $controller = new ControllerFixture();
        $request = (new Request())->withQueryParams(['page' => '3']);
        $response = new Response();
        self::assertSame($controller, $controller->setRequest($request)->setResponse($response));
        self::assertSame($request, $controller->getRequest());
        self::assertSame($response, $controller->getResponse());
        self::assertSame(3, $controller->pageValue());
        self::assertSame(100, $controller->offset(50));
    }

    public function testResponseHelpersRemainErgonomic(): void
    {
        $response = (new ControllerFixture())->makeJson();
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('{"ok":true}', $response->getContent());
    }
}
