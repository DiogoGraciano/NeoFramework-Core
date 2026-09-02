<?php
declare(strict_types=1);

namespace NeoFramework\Core;

use GuzzleHttp\Psr7\Utils;
use NeoFramework\Core\Auth\AuthContext;
use NeoFramework\Core\Auth\IdentityInterface;
use NeoFramework\Core\Config\AppConfig;
use NeoFramework\Core\Config\ConfigRepositoryInterface;
use NeoFramework\Core\Config\CorsConfig;
use NeoFramework\Core\Config\SecurityHeadersConfig;
use NeoFramework\Core\Debug\DebugCollector;
use NeoFramework\Core\Events\Events;
use NeoFramework\Core\Events\RequestReceived;
use NeoFramework\Core\Events\ResponseCreated;
use NeoFramework\Core\Http\MiddlewareResolver;
use NeoFramework\Core\Http\Pipeline;
use NeoFramework\Core\Http\RequestAttributes;
use NeoFramework\Core\Http\RequestScope;
use NeoFramework\Core\Http\RequestScopeContext;
use NeoFramework\Core\Http\RoutingHandler;
use NeoFramework\Core\Middleware\Cors;
use NeoFramework\Core\Middleware\DebugToolbarMiddleware;
use NeoFramework\Core\Middleware\ErrorHandler;
use NeoFramework\Core\Middleware\SecurityHeaders;
use NeoFramework\Core\Routing\Matcher;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class HttpKernel
{
    private MiddlewareResolver $resolver;
    public function __construct(private ContainerInterface $container, private Matcher $matcher, private array $middleware = [], private bool $collectsDebugProfiles = false)
    {
        $this->resolver = new MiddlewareResolver($container);
    }
    public static function create(?ContainerInterface $container = null): self
    {
        $container ??= new Container();
        $config = $container->get(ConfigRepositoryInterface::class);
        $middleware = [];

        // Antes de tudo: a toolbar precisa medir o ciclo inteiro, inclusive o
        // tempo gasto nos outros middlewares. Só existe fora de produção — um
        // profiler exposto é um mapa da aplicação para quem o alcançar.
        $debug = AppConfig::from($config)->environment !== 'prod';
        if ($debug) $middleware[] = new DebugToolbarMiddleware(true);

        $securityHeaders = SecurityHeadersConfig::from($config);
        if ($securityHeaders->enabled) $middleware[] = new SecurityHeaders($securityHeaders->headers, $securityHeaders->viteCspRelax);
        $cors = CorsConfig::from($config);
        if ($cors->enabled) $middleware[] = new Cors($cors->middleware());
        $configured = \NeoFramework\Core\Support\ProjectRoot::path() . 'Config' . DIRECTORY_SEPARATOR . 'middleware.config.php';
        $middleware[] = ErrorHandler::class;
        if (is_file($configured)) $middleware = [...$middleware, ...(array) include $configured];
        return new self($container, new Matcher(RouteCache::get()), $middleware, $debug);
    }
    /**
     * ID da requisição.
     *
     * Aceita o `X-Request-Id` do proxy — é o que permite correlacionar o log da
     * aplicação com o do balanceador —, mas só quando o proxy é confiável, e
     * saneado: o valor vai para header de resposta e para log.
     */
    private function requestId(ServerRequestInterface $request): string
    {
        $forwarded = $request->getHeaderLine('X-Request-Id');

        if ($forwarded !== '' && Url::isFromTrustedProxy() && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $forwarded) === 1) {
            return $forwarded;
        }

        return bin2hex(random_bytes(16));
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = \NeoFramework\Core\Events\DispatcherFactory::fromContainer($this->container);

        // O coletor é instanciado POR REQUISIÇÃO e ligado aqui, e não em
        // `Config/events.php`: registrado por class-string ele viraria um
        // serviço compartilhado, e num worker somaria as consultas de todo
        // mundo, atribuindo à última requisição a contagem das anteriores.
        $provider = $dispatcher instanceof \NeoFramework\Core\Events\EventDispatcher ? $dispatcher->provider() : null;

        if ($this->collectsDebugProfiles && $provider instanceof \NeoFramework\Core\Events\ListenerProvider) {
            $collector = new DebugCollector();
            foreach ([
                \NeoFramework\Core\Events\ControllerInvoked::class,
                \NeoFramework\Core\Events\CacheAccessed::class,
                \NeoFramework\Core\Events\QueryExecuted::class,
                \NeoFramework\Core\Events\ResponseCreated::class,
            ] as $event) {
                $provider->on($event, $collector);
            }
        }

        return $dispatcher;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $scope = new RequestScope();
        RequestScopeContext::enter($scope);
        $request = $request->withAttribute(RequestAttributes::SCOPE, $scope);
        $scope->set(ServerRequestInterface::class, $request);
        // A sessão PHP é carregada antes do kernel no FPM; o escopo recebe um
        // snapshot e só o devolve ao storage ativo ao terminar a requisição.
        $scope->set('neoframework.session', session_status() === PHP_SESSION_ACTIVE && isset($_SESSION) ? $_SESSION : []);
        $requestId = $this->requestId($request);
        $scope->set('neoframework.request_id', $requestId);
        $scope->set('neoframework.log_context', ['requestId' => $requestId]);
        $scope->set(Events::KEY, $this->dispatcher());
        $seededIdentity = $request->getAttribute(RequestAttributes::AUTH_IDENTITY);
        if ($seededIdentity instanceof IdentityInterface) {
            AuthContext::set($seededIdentity, (string) $request->getAttribute(RequestAttributes::AUTH_GUARD, 'session'));
        }
        $startedAt = microtime(true);

        try {
            Events::dispatch(new RequestReceived($request, $requestId));
            $response = (new Pipeline($this->middleware, $this->resolver, new RoutingHandler($this->matcher, $this->container, $this->resolver)))->handle($request);
            // O ID acompanha a resposta: sem isso não há como ligar o que o
            // usuário viu ao que ficou no log.
            $response = $response->withHeader('X-Request-Id', $requestId);
            if (strtoupper($request->getMethod()) === 'HEAD') $response = $response->withBody(Utils::streamFor(''));
            Events::dispatch(new ResponseCreated($request, $response, (microtime(true) - $startedAt) * 1000));
            return $response;
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $session = $scope->get('neoframework.session', []);
                $_SESSION = $session;
            }
            $scope->reset();
            RequestScopeContext::leave($scope);
        }
    }
}
