<?php

declare(strict_types=1);

namespace NeoFramework\Core\Middleware;

use NeoFramework\Core\Debug\ProfileStore;
use NeoFramework\Core\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Marca cada resposta com um token e serve os perfis coletados.
 *
 * Serve o endpoint aqui em vez de registrar um controller porque a toolbar não
 * deve depender da descoberta de rotas da aplicação: ela precisa funcionar
 * justamente quando o roteamento está errado.
 *
 * **Nunca é montado em produção.** Não por configuração esquecida: o construtor
 * recebe `enabled` e o bootstrap o resolve a partir de `app.environment`. Um
 * profiler exposto é um mapa da aplicação para quem o alcançar.
 */
final readonly class DebugToolbarMiddleware implements MiddlewareInterface
{
    public const PREFIX = '/_debug';

    public function __construct(private bool $enabled, private ProfileStore $store = new ProfileStore())
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->enabled) return $handler->handle($request);

        $path = rtrim($request->getUri()->getPath(), '/');

        if ($path === self::PREFIX) return $this->index();
        if (str_starts_with($path, self::PREFIX . '/')) return $this->show(substr($path, strlen(self::PREFIX) + 1));

        // O token vai na resposta antes de o listener gravar: é ele que liga a
        // requisição ao perfil, e o cliente precisa dele para consultar depois.
        return $handler->handle($request)->withHeader('X-Debug-Token', bin2hex(random_bytes(8)));
    }

    private function index(): ResponseInterface
    {
        return (new Response())->json(['profiles' => $this->store->recent()]);
    }

    private function show(string $token): ResponseInterface
    {
        $profile = $this->store->find($token);

        return $profile === null
            ? (new Response())->json(['error' => 'Perfil não encontrado.'], 404)
            : (new Response())->json($profile);
    }
}
