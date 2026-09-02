<?php
declare(strict_types=1);

namespace NeoFramework\Core\Middleware;

use NeoFramework\Core\Http\ConditionalRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Transforma em 304 a resposta que o cliente já tem.
 *
 * Só age sobre 200 de GET/HEAD que já carregam validador: gerar ETag aqui
 * exigiria ler o corpo inteiro, trocando banda por memória sem o controller
 * pedir. Quem quer 304 declara `ETag` ou `Last-Modified` na resposta.
 */
final class ConditionalRequestMiddleware implements MiddlewareInterface
{
    /** Headers que descrevem o corpo perdem o sentido quando não há corpo. */
    private const BODY_HEADERS = ['Content-Length', 'Content-Type', 'Content-Encoding', 'Content-Language', 'Content-Range'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true) || $response->getStatusCode() !== 200) return $response;

        $etag = $response->getHeaderLine('ETag') ?: null;
        $lastModified = $response->getHeaderLine('Last-Modified') !== '' ? strtotime($response->getHeaderLine('Last-Modified')) : null;
        if ($etag === null && $lastModified === false) return $response;

        if (!ConditionalRequest::isFresh($request, $etag, $lastModified === false ? null : $lastModified)) return $response;

        $notModified = $response->withStatus(304)->withBody(\GuzzleHttp\Psr7\Utils::streamFor(''));
        foreach (self::BODY_HEADERS as $header) $notModified = $notModified->withoutHeader($header);

        return $notModified;
    }
}
