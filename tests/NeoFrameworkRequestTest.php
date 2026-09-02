<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class NeoFrameworkRequestTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET = []; $_POST = []; $_COOKIE = []; $_FILES = [];
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/users?page=2', 'HTTP_HOST' => 'example.test'];
    }

    public function testFromGlobalsProducesPsr7RequestAndSnapshotsInput(): void
    {
        $_GET = ['page' => '2']; $_POST = ['name' => 'Neo']; $_COOKIE = ['theme' => 'dark'];
        $request = Request::fromGlobals();
        self::assertInstanceOf(ServerRequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/users', $request->getUri()->getPath());
        self::assertSame('2', $request->get('page'));
        self::assertSame('Neo', $request->post('name'));
        self::assertSame('dark', $request->cookie('theme'));
        $_GET['page'] = '9';
        self::assertSame('2', $request->get('page'));
    }

    public function testHeadersAreImmutableAndCaseInsensitive(): void
    {
        $request = new Request('GET', '/', ['X-Test' => 'one']);
        $changed = $request->withHeader('x-test', 'two');
        self::assertSame('one', $request->getHeaderLine('X-Test'));
        self::assertSame('two', $changed->getHeaderLine('X-Test'));
    }

    public function testConvenienceAccessorsAndSanitizing(): void
    {
        $request = (new Request('POST', '/', ['Content-Type' => 'application/json'], '{"json":true}'))
            ->withQueryParams(['unsafe' => '<b>x</b>'])
            ->withParsedBody(['form' => 'yes']);
        self::assertSame('&lt;b&gt;x&lt;/b&gt;', $request->get('unsafe', true));
        self::assertSame(['unsafe' => '<b>x</b>', 'form' => 'yes', 'json' => true], $request->all());
        self::assertSame(['json' => true], $request->getBodyAsJson(true));
    }

    public function testCsrfHeaderFallback(): void
    {
        $request = new Request('POST', '/', ['X-CSRF-TOKEN' => 'token']);
        self::assertSame('token', $request->getCsrfToken());
    }
}
