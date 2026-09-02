<?php
declare(strict_types=1);

namespace Tests;

use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\ServerRequest;
use NeoFramework\Core\Exceptions\NotFoundException;
use NeoFramework\Core\Http\CacheControl;
use NeoFramework\Core\Http\CallbackStream;
use NeoFramework\Core\Http\ConditionalRequest;
use NeoFramework\Core\Http\Cookie;
use NeoFramework\Core\Http\FileResponseFactory;
use NeoFramework\Core\Http\ResponseEmitter;
use NeoFramework\Core\Http\ServerSentEvent;
use NeoFramework\Core\Http\StreamedResponseFactory;
use NeoFramework\Core\Middleware\ConditionalRequestMiddleware;
use NeoFramework\Core\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class NeoFrameworkResponsesTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neof-file-' . bin2hex(random_bytes(6)) . '.txt';
        file_put_contents($this->file, 'ABCDEFGHIJ');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function get(array $headers = []): ServerRequestInterface
    {
        return new ServerRequest('GET', '/download', $headers);
    }

    public function testAFullDownloadCarriesLengthValidatorsAndDisposition(): void
    {
        $response = FileResponseFactory::download($this->file, 'relatório final.txt', 'text/plain');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('10', $response->getHeaderLine('Content-Length'));
        self::assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertNotSame('', $response->getHeaderLine('ETag'));
        self::assertNotSame('', $response->getHeaderLine('Last-Modified'));
        // RFC 6266: nome ASCII para clientes antigos e UTF-8 para os demais.
        self::assertSame('attachment; filename="relat_rio final.txt"; filename*=UTF-8\'\'relat%C3%B3rio%20final.txt', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('ABCDEFGHIJ', (string) $response->getBody());
    }

    public function testAMissingFileIsA404AndNotA500(): void
    {
        $this->expectException(NotFoundException::class);
        FileResponseFactory::download($this->file . '-nao-existe');
    }

    /** @return array<string,array{string,int,string,string}> */
    public static function ranges(): array
    {
        return [
            'início' => ['bytes=0-3', 206, 'ABCD', 'bytes 0-3/10'],
            'aberto à direita' => ['bytes=6-', 206, 'GHIJ', 'bytes 6-9/10'],
            'sufixo' => ['bytes=-3', 206, 'HIJ', 'bytes 7-9/10'],
            'sufixo maior que o arquivo' => ['bytes=-50', 206, 'ABCDEFGHIJ', 'bytes 0-9/10'],
            'fim além do arquivo é truncado' => ['bytes=8-99', 206, 'IJ', 'bytes 8-9/10'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ranges')]
    public function testRangeRequestsServeExactlyTheRequestedBytes(string $header, int $status, string $body, string $contentRange): void
    {
        $response = FileResponseFactory::download($this->file, request: $this->get(['Range' => $header]));

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($contentRange, $response->getHeaderLine('Content-Range'));
        self::assertSame($body, (string) $response->getBody());
        // O Content-Length precisa descrever o corpo enviado, não o arquivo.
        self::assertSame((string) strlen($body), $response->getHeaderLine('Content-Length'));
    }

    /** @return array<string,array{string}> */
    public static function unsatisfiableRanges(): array
    {
        return ['início além do fim' => ['bytes=50-60'], 'invertido' => ['bytes=8-2'], 'sufixo zero' => ['bytes=-0'], 'lixo' => ['bytes=abc']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsatisfiableRanges')]
    public function testAnUnsatisfiableRangeIs416WithTheRealSize(string $header): void
    {
        $response = FileResponseFactory::download($this->file, request: $this->get(['Range' => $header]));

        self::assertSame(416, $response->getStatusCode());
        self::assertSame('bytes */10', $response->getHeaderLine('Content-Range'));
    }

    /** Múltiplos intervalos exigiriam multipart/byteranges; 200 é a resposta honesta. */
    public function testMultipleRangesFallBackToTheWholeFile(): void
    {
        $response = FileResponseFactory::download($this->file, request: $this->get(['Range' => 'bytes=0-1,4-5']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ABCDEFGHIJ', (string) $response->getBody());
    }

    /** Retomar um download de um arquivo que mudou tem que mandar o arquivo todo. */
    public function testAStaleIfRangeGetsTheFullFile(): void
    {
        $response = FileResponseFactory::download($this->file, request: $this->get(['Range' => 'bytes=0-3', 'If-Range' => '"etag-de-outra-versao"']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ABCDEFGHIJ', (string) $response->getBody());
    }

    public function testAMatchingIfRangeStillServesThePartialContent(): void
    {
        $etag = FileResponseFactory::download($this->file)->getHeaderLine('ETag');
        $response = FileResponseFactory::download($this->file, request: $this->get(['Range' => 'bytes=0-3', 'If-Range' => $etag]));

        self::assertSame(206, $response->getStatusCode());
    }

    public function testAFreshCacheGets304FromTheFileFactory(): void
    {
        $etag = FileResponseFactory::download($this->file)->getHeaderLine('ETag');
        $response = FileResponseFactory::download($this->file, request: $this->get(['If-None-Match' => $etag]));

        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    public function testAWeakValidatorMatchesTheStrongOneOnAConditionalRequest(): void
    {
        $request = $this->get(['If-None-Match' => 'W/"abc"']);

        self::assertTrue(ConditionalRequest::isFresh($request, '"abc"', null));
        self::assertFalse(ConditionalRequest::isFresh($request, '"outro"', null));
        self::assertTrue(ConditionalRequest::isFresh($this->get(['If-None-Match' => '*']), '"qualquer"', null));
    }

    /** O ETag vence o timestamp quando os dois chegam. */
    public function testETagWinsOverModifiedSince(): void
    {
        $request = $this->get(['If-None-Match' => '"atual"', 'If-Modified-Since' => gmdate('D, d M Y H:i:s T', time() + 3600)]);

        self::assertFalse(ConditionalRequest::isFresh($request, '"mudou"', time()));
    }

    public function testTheMiddlewareTurnsAFreshResponseInto304WithoutBodyHeaders(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new PsrResponse(200, ['ETag' => '"v1"', 'Content-Type' => 'text/html', 'Content-Length' => '5'], 'corpo');
            }
        };

        $fresh = (new ConditionalRequestMiddleware())->process($this->get(['If-None-Match' => '"v1"']), $handler);
        self::assertSame(304, $fresh->getStatusCode());
        self::assertSame('', (string) $fresh->getBody());
        self::assertFalse($fresh->hasHeader('Content-Length'));
        self::assertFalse($fresh->hasHeader('Content-Type'));

        $stale = (new ConditionalRequestMiddleware())->process($this->get(['If-None-Match' => '"v0"']), $handler);
        self::assertSame(200, $stale->getStatusCode());
        self::assertSame('corpo', (string) $stale->getBody());
    }

    public function testTheMiddlewareLeavesResponsesWithoutValidatorsAlone(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface { return new PsrResponse(200, [], 'corpo'); }
        };

        self::assertSame(200, (new ConditionalRequestMiddleware())->process($this->get(['If-None-Match' => '"v1"']), $handler)->getStatusCode());
    }

    public function testCacheControlRendersTheIntendedDirectives(): void
    {
        self::assertSame('no-store, no-cache', CacheControl::noStore()->toHeader());
        self::assertSame('private, no-cache, max-age=0', CacheControl::private()->toHeader());
        self::assertSame('private, max-age=60', CacheControl::private(60)->toHeader());
        self::assertSame('public, max-age=3600, s-maxage=86400, immutable', CacheControl::public(3600, 86400, immutable: true)->toHeader());
        self::assertSame('public, max-age=60, stale-while-revalidate=30', CacheControl::public(60)->withStaleWhileRevalidate(30)->toHeader());
    }

    /** O navegador descarta SameSite=None sem Secure — falhar cedo é melhor que sessão que some. */
    public function testSameSiteNoneRequiresSecure(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cookie('sessao', 'x', sameSite: 'None');
    }

    public function testCookieRefusesAnInjectedName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cookie("sessao\r\nSet-Cookie: admin=1");
    }

    public function testResponseCookiesCarryTheSecurityAttributes(): void
    {
        $header = (new Response())->withCookie('sessao', 'abc', secure: true)->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('sessao=abc', $header);
        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('Secure', $header);
        self::assertStringContainsString('SameSite=Lax', $header);
    }

    public function testTheJsonStreamEmitsItemsWithoutBuildingTheWholeArray(): void
    {
        $response = StreamedResponseFactory::json((static function (): \Generator {
            yield ['id' => 1];
            yield ['id' => 2];
        })());

        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('[{"id":1},{"id":2}]', (string) $response->getBody());
    }

    public function testAGeneratedBodyIsPulledInChunksAndNotAllAtOnce(): void
    {
        $produced = 0;
        $stream = new CallbackStream(static function () use (&$produced): \Generator {
            foreach (range(1, 100) as $i) {
                $produced++;
                yield str_pad((string) $i, 10, '.');
            }
        });

        $first = $stream->read(10);

        self::assertSame(10, strlen($first));
        // Ler 10 bytes não pode ter custado os 100 blocos.
        self::assertLessThanOrEqual(2, $produced);
        self::assertFalse($stream->eof());
    }

    public function testServerSentEventsRenderTheWireFormat(): void
    {
        self::assertSame("data: oi\n\n", (new ServerSentEvent('oi'))->toWire());
        self::assertSame("id: 7\nevent: ping\nretry: 3000\ndata: a\ndata: b\n\n", (new ServerSentEvent("a\nb", 'ping', '7', 3000))->toWire());
    }

    /** Uma quebra de linha no id injetaria um evento que ninguém emitiu. */
    public function testServerSentEventsRefuseInjectedFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ServerSentEvent('ok', id: "1\ndata: injetado");
    }

    public function testTheEventStreamDisablesProxyBuffering(): void
    {
        $response = StreamedResponseFactory::eventStream(static fn (): \Generator => yield new ServerSentEvent('oi'));

        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertSame('no', $response->getHeaderLine('X-Accel-Buffering'));
        self::assertSame('no-store, no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertSame("data: oi\n\n", (string) $response->getBody());
    }

    /** 304 e 204 não têm corpo; emitir bytes ali corrompe a resposta seguinte. */
    public function testTheEmitterSendsNoBodyForBodylessStatuses(): void
    {
        foreach ([204, 304] as $status) {
            ob_start();
            (new ResponseEmitter())->emit(new PsrResponse($status, [], 'nao-deveria-sair'));
            self::assertSame('', (string) ob_get_clean());
        }

        ob_start();
        (new ResponseEmitter())->emit(new PsrResponse(200, [], 'corpo'));
        self::assertSame('corpo', (string) ob_get_clean());
    }
}
