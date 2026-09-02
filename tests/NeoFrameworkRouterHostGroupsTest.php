<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Attributes\RouteHost;
use NeoFramework\Core\Response;
use NeoFramework\Core\Routing\AttributeLoader;
use NeoFramework\Core\Routing\Matcher;
use NeoFramework\Core\Routing\RouteCompiler;
use NeoFramework\Core\Routing\RouteRegistrar;
use NeoFramework\Core\Routing\UrlGenerator;
use PHPUnit\Framework\TestCase;

#[RouteHost('{tenant}.example.com')]
final class TenantControllerFixture extends Controller
{
    #[Route('/painel', ['GET'], false, 'tenant.painel')]
    public function painel(string $tenant): Response { return $this->json(['tenant' => $tenant]); }

    /** O host do método vence o da classe. */
    #[RouteHost('admin.example.com')]
    #[Route('/interno', ['GET'], false, 'tenant.interno')]
    public function interno(): Response { return $this->json(['ok' => true]); }
}

final class PublicControllerFixture extends Controller
{
    #[Route('/painel', ['GET'], false, 'publico.painel')]
    public function painel(): Response { return $this->json(['publico' => true]); }

    #[Route('/artigos/{slug}', ['GET'], false, 'artigos.slug')]
    public function slug(string $slug): Response { return $this->json(['slug' => $slug]); }

    /**
     * Prioridade maior, e declarada DEPOIS: as duas são dinâmicas e ambas casam
     * "/artigos/123", então só a prioridade separa.
     */
    #[Route('/artigos/{id:\d+}', ['GET'], false, 'artigos.id', priority: 10)]
    public function porId(string $id): Response { return $this->json(['id' => $id]); }

    #[Route('/relatorio/{formato?}', ['GET'], false, 'relatorio', defaults: ['formato' => 'pdf'])]
    public function relatorio(?string $formato = null): Response { return $this->json(['formato' => $formato]); }
}

final class GroupControllerFixture extends Controller
{
    public function index(): Response { return $this->json(['ok' => true]); }

    public function store(): Response { return $this->json(['ok' => true]); }
}

final class NeoFrameworkRouterHostGroupsTest extends TestCase
{
    private function matcher(array $controllers): Matcher
    {
        return new Matcher(RouteCompiler::compile((new AttributeLoader())->load($controllers)));
    }

    public function testAHostScopedRouteOnlyMatchesItsHost(): void
    {
        $matcher = $this->matcher([TenantControllerFixture::class]);

        $match = $matcher->match('GET', '/painel', 'acme.example.com');
        self::assertSame('found', $match->status);
        self::assertSame('acme', $match->variables['tenant']);

        // Outro host não pode alcançar a rota: é isso que `#[RouteHost]` promete.
        self::assertSame('not_found', $matcher->match('GET', '/painel', 'outro.dominio.com')->status);
    }

    public function testTheDefaultHostConstraintStopsAtTheDot(): void
    {
        $matcher = $this->matcher([TenantControllerFixture::class]);

        // `{tenant}` casando ponto faria `a.b.example.com` alcançar de outro
        // nível uma rota que se acredita restrita a um subdomínio.
        self::assertSame('not_found', $matcher->match('GET', '/painel', 'a.b.example.com')->status);
    }

    public function testTheMethodHostOverridesTheClassHost(): void
    {
        $matcher = $this->matcher([TenantControllerFixture::class]);

        self::assertSame('found', $matcher->match('GET', '/interno', 'admin.example.com')->status);
        self::assertSame('not_found', $matcher->match('GET', '/interno', 'acme.example.com')->status);
    }

    public function testTheSamePathCanExistOnDifferentHosts(): void
    {
        $matcher = $this->matcher([TenantControllerFixture::class, PublicControllerFixture::class]);

        // Sem o host na assinatura da coleção, isto seria recusado como rota
        // duplicada — que é justamente o desenho que o atributo permite.
        self::assertSame('acme', $matcher->match('GET', '/painel', 'acme.example.com')->variables['tenant'] ?? null);
        self::assertSame('found', $matcher->match('GET', '/painel', 'qualquer.coisa.com')->status);
    }

    public function testARouteWithoutHostStillMatchesAnyHost(): void
    {
        $matcher = $this->matcher([PublicControllerFixture::class]);

        self::assertSame('found', $matcher->match('GET', '/painel', 'a.com')->status);
        self::assertSame('found', $matcher->match('GET', '/painel', null)->status);
    }

    public function testARequestWithoutHostCannotReachAHostScopedRoute(): void
    {
        $matcher = $this->matcher([TenantControllerFixture::class]);

        // Aceitar aqui faria a restrição sumir sempre que o Host não chegasse.
        self::assertSame('not_found', $matcher->match('GET', '/painel', null)->status);
    }

    public function testThePortIsNotPartOfTheHostPattern(): void
    {
        $matcher = $this->matcher([TenantControllerFixture::class]);

        self::assertSame('found', $matcher->match('GET', '/painel', 'acme.example.com:8080')->status);
    }

    public function testHostMatchingIsCaseInsensitive(): void
    {
        $matcher = $this->matcher([TenantControllerFixture::class]);

        self::assertSame('found', $matcher->match('GET', '/painel', 'ACME.Example.COM')->status);
    }

    public function testAllowDoesNotAnnounceMethodsFromAnotherHost(): void
    {
        $matcher = $this->matcher([TenantControllerFixture::class]);

        // Anunciar GET de um host que não atende esta requisição é informação
        // errada para o cliente.
        self::assertSame('not_found', $matcher->match('POST', '/painel', 'outro.com')->status);
    }

    public function testPriorityBeatsDeclarationOrder(): void
    {
        $matcher = $this->matcher([PublicControllerFixture::class]);

        $match = $matcher->match('GET', '/artigos/123');
        self::assertSame('found', $match->status);
        // Sem prioridade, `slug` venceria por ter sido declarada antes.
        self::assertSame('porId', $match->route?->action);
        self::assertSame('slug', $matcher->match('GET', '/artigos/ola')->route?->action);
    }

    public function testTiedPrioritiesKeepDeclarationOrder(): void
    {
        $routes = RouteCompiler::compile((new AttributeLoader())->load([PublicControllerFixture::class]));
        $actions = array_column($routes['dynamic']['GET'], 'action');

        // `porId` tem prioridade 10; o resto empata em 0 e mantém a ordem em
        // que os métodos aparecem na classe.
        self::assertSame('porId', $actions[0]);
        self::assertSame(['slug', 'relatorio'], array_slice($actions, 1));
    }

    public function testDefaultsFillAnAbsentOptionalVariable(): void
    {
        $matcher = $this->matcher([PublicControllerFixture::class]);

        self::assertSame('pdf', $matcher->match('GET', '/relatorio')->variables['formato'] ?? null);
        // O valor presente na URL sempre vence o default.
        self::assertSame('csv', $matcher->match('GET', '/relatorio/csv')->variables['formato'] ?? null);
    }

    public function testTheUrlGeneratorBuildsTheRouteOwnHost(): void
    {
        $compiled = RouteCompiler::compile((new AttributeLoader())->load([TenantControllerFixture::class]));
        $generator = new UrlGenerator($compiled['named'], '', 'https://example.com');

        self::assertSame('https://acme.example.com/painel', $generator->url('tenant.painel', ['tenant' => 'acme']));
    }

    public function testGeneratingAHostRouteWithoutItsVariableFails(): void
    {
        $compiled = RouteCompiler::compile((new AttributeLoader())->load([TenantControllerFixture::class]));
        $generator = new UrlGenerator($compiled['named'], '', 'https://example.com');

        $this->expectException(\InvalidArgumentException::class);
        $generator->url('tenant.painel');
    }

    public function testAHostVariableMustBeBoundByTheAction(): void
    {
        $this->expectException(\LogicException::class);
        (new AttributeLoader())->load([UnboundHostControllerFixture::class]);
    }

    public function testGroupsAccumulatePrefixNameAndMiddleware(): void
    {
        $registrar = new RouteRegistrar();
        $registrar->group(['prefix' => '/api', 'name' => 'api.', 'middleware' => ['MwA']], function (RouteRegistrar $r): void {
            $r->group(['prefix' => '/v1', 'name' => 'v1.', 'middleware' => ['MwB']], function (RouteRegistrar $r): void {
                $r->get('/users', [GroupControllerFixture::class, 'index'], name: 'users.index');
            });
        });

        $route = $registrar->all()[0];
        self::assertSame('/api/v1/users', $route->path);
        self::assertSame('api.v1.users.index', $route->name);
        self::assertSame(['MwA', 'MwB'], $route->middleware);
    }

    public function testAnInnerGroupCanLeaveTheOuterHost(): void
    {
        $registrar = new RouteRegistrar();
        $registrar->group(['host' => 'app.example.com'], function (RouteRegistrar $r): void {
            $r->get('/a', [GroupControllerFixture::class, 'index']);
            $r->group(['host' => 'admin.example.com'], function (RouteRegistrar $r): void {
                $r->get('/b', [GroupControllerFixture::class, 'index']);
            });
            $r->get('/c', [GroupControllerFixture::class, 'index']);
        });

        $hosts = array_map(static fn ($r): ?string => $r->host, $registrar->all());
        // A terceira rota volta ao host externo: o contexto é restaurado ao sair.
        self::assertSame(['app.example.com', 'admin.example.com', 'app.example.com'], $hosts);
    }

    public function testTheGroupContextIsRestoredEvenWhenTheCallbackThrows(): void
    {
        $registrar = new RouteRegistrar();

        try {
            $registrar->group(['prefix' => '/quebrado'], static function (): void {
                throw new \RuntimeException('falhou');
            });
        } catch (\RuntimeException) {
        }

        // Sem restaurar, a rota abaixo sairia em /quebrado/depois — num lugar
        // que ninguém declarou.
        $registrar->get('/depois', [GroupControllerFixture::class, 'index']);
        self::assertSame('/depois', $registrar->all()[0]->path);
    }

    public function testAProgrammaticRouteIsMatchedLikeAnyOther(): void
    {
        $registrar = new RouteRegistrar();
        $registrar->group(['prefix' => '/api'], function (RouteRegistrar $r): void {
            $r->post('/users', [GroupControllerFixture::class, 'store'], name: 'users.store');
        });

        $matcher = new Matcher(RouteCompiler::compile($registrar->toCollection()));

        self::assertSame('found', $matcher->match('POST', '/api/users')->status);
        self::assertSame('store', $matcher->match('POST', '/api/users')->route?->action);
    }

    public function testAProgrammaticRouteToAMissingActionIsRefusedAtRegistration(): void
    {
        $registrar = new RouteRegistrar();

        // Só falharia ao servir a rota, com 500. Recusar aqui mantém a mesma
        // promessa que os atributos já dão.
        $this->expectException(\LogicException::class);
        $registrar->get('/x', [GroupControllerFixture::class, 'naoExiste']);
    }

    public function testTheFallbackAnswersWhenNothingMatches(): void
    {
        $matcher = $this->matcher([FallbackControllerFixture::class]);

        $match = $matcher->match('GET', '/rota/que/nao/existe');
        self::assertSame('found', $match->status);
        self::assertSame('naoAchou', $match->route?->action);
    }

    public function testTheFallbackDoesNotShadowARealRoute(): void
    {
        $matcher = $this->matcher([FallbackControllerFixture::class]);

        self::assertSame('existe', $matcher->match('POST', '/existe')->route?->action);
    }

    public function testTheFallbackDoesNotTurnA405IntoA404(): void
    {
        $matcher = $this->matcher([FallbackControllerFixture::class]);

        // Um método errado numa rota que existe continua sendo 405: virar 404
        // esconderia do cliente que o recurso existe.
        self::assertSame('method_not_allowed', $matcher->match('DELETE', '/existe')->status);
    }

    public function testAMethodWithoutFallbackStillGets404(): void
    {
        $matcher = $this->matcher([FallbackControllerFixture::class]);

        self::assertSame('not_found', $matcher->match('DELETE', '/nada/aqui')->status);
    }

    public function testTwoFallbacksForTheSameMethodAreRefused(): void
    {
        // Um dos dois nunca rodaria, e saber qual exigiria ler a ordem de
        // descoberta dos controllers.
        $this->expectException(\LogicException::class);
        (new AttributeLoader())->load([FallbackControllerFixture::class, SecondFallbackControllerFixture::class]);
    }

    public function testProgrammaticAndAttributeRoutesShareTheDuplicateCheck(): void
    {
        $collection = (new AttributeLoader())->load([PublicControllerFixture::class]);
        $registrar = new RouteRegistrar();
        $registrar->get('/painel', [GroupControllerFixture::class, 'index']);

        // Compilar em separado deixaria a rota programática sombrear a de
        // atributo em silêncio.
        $this->expectException(\LogicException::class);
        $registrar->toCollection($collection);
    }
}

final class FallbackControllerFixture extends Controller
{
    #[Route('/existe', ['POST'], false)]
    public function existe(): Response { return $this->json(['ok' => true]); }

    #[\NeoFramework\Core\Attributes\RouteFallback(['GET', 'POST'])]
    public function naoAchou(): Response { return $this->json(['fallback' => true], 404); }
}

final class UnboundHostControllerFixture extends Controller
{
    #[RouteHost('{tenant}.example.com')]
    #[Route('/sem-parametro', ['GET'], false)]
    public function semParametro(): Response { return $this->json(['ok' => true]); }
}

final class SecondFallbackControllerFixture extends Controller
{
    #[\NeoFramework\Core\Attributes\RouteFallback(['GET'])]
    public function outro(): Response { return $this->json(['outro' => true]); }
}
