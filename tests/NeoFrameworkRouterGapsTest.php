<?php

declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Attributes\RoutePrefix;
use NeoFramework\Core\Response;
use NeoFramework\Core\Routing\AttributeLoader;
use NeoFramework\Core\Routing\RouteCompiler;
use NeoFramework\Core\Routing\RouteConflictDetector;
use NeoFramework\Core\Routing\SignedUrlGenerator;
use NeoFramework\Core\Routing\UrlGenerator;
use NeoFramework\Core\Testing\FakeClock;
use NeoFramework\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;

#[RoutePrefix('/api/v1', name: 'api.v1.')]
final class PrefixedControllerFixture extends Controller
{
    #[Route('/orders/{id:\d+}', ['GET'], false, 'orders.show')]
    public function show(int $id): Response
    {
        return $this->json(['id' => $id]);
    }

    #[Route('/orders', ['POST'], false)]
    public function store(): Response
    {
        return $this->json([], 201);
    }

    #[Route('/orders/opcoes', ['OPTIONS'], false, 'orders.options')]
    public function options(): Response
    {
        return $this->json(['declarada' => true]);
    }
}

final class NeoFrameworkRouterGapsTest extends TestCase
{
    /** @return array<string,array<string,mixed>> */
    private function named(): array
    {
        return RouteCompiler::compile((new AttributeLoader())->load([PrefixedControllerFixture::class]))['named'];
    }

    private function urls(string $baseUrl = ''): UrlGenerator
    {
        return new UrlGenerator($this->named(), '', $baseUrl);
    }

    public function testTheNamePrefixIsAppliedToNamedRoutesOnly(): void
    {
        $named = $this->named();

        self::assertArrayHasKey('api.v1.orders.show', $named);
        self::assertSame('/api/v1/orders/{id:\d+}', $named['api.v1.orders.show']['path']);
        self::assertArrayHasKey('api.v1.orders.options', $named);

        // A action sem nome não ganha um nome só por existir o prefixo.
        $actions = array_column($named, 'action');
        self::assertNotContains('store', $actions);
    }

    public function testGeneratingAUrlWithAValueTheRouteCannotMatchIsRefused(): void
    {
        self::assertSame('/api/v1/orders/42', $this->urls()->path('api.v1.orders.show', ['id' => 42]));

        // Sem a checagem isto devolvia /api/v1/orders/abc — um link que responde
        // 404 sem avisar nada a quem o gerou.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/'id'/");
        $this->urls()->path('api.v1.orders.show', ['id' => 'abc']);
    }

    public function testAbsoluteUrlsNeedAConfiguredBase(): void
    {
        self::assertSame(
            'https://exemplo.test/api/v1/orders/7',
            $this->urls('https://exemplo.test/')->url('api.v1.orders.show', ['id' => 7]),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->urls()->url('api.v1.orders.show', ['id' => 7]);
    }

    public function testASignedUrlSurvivesTheRoundTripAndBreaksIfAltered(): void
    {
        $signer = new SignedUrlGenerator($this->urls(), 'chave-secreta');
        $signed = $signer->sign('api.v1.orders.show', ['id' => 7]);

        self::assertTrue($signer->isValid($signed));
        self::assertFalse($signer->isValid(str_replace('/7', '/8', $signed)));
        self::assertFalse($signer->isValid('/api/v1/orders/7'));
    }

    public function testAQueryStringIsPartOfWhatIsSigned(): void
    {
        $signer = new SignedUrlGenerator($this->urls(), 'chave-secreta');
        $signed = $signer->sign('api.v1.orders.show', ['id' => 7], ['acao' => 'ver']);

        self::assertTrue($signer->isValid($signed));
        // Trocar "ver" por "excluir" mantendo a assinatura tem que ser recusado.
        self::assertFalse($signer->isValid(str_replace('acao=ver', 'acao=excluir', $signed)));
    }

    public function testAnExpiredSignatureIsRefused(): void
    {
        $clock = new FakeClock(1000);
        $signer = new SignedUrlGenerator($this->urls(), 'chave-secreta', $clock);
        $signed = $signer->sign('api.v1.orders.show', ['id' => 7], expiresInSeconds: 60);

        self::assertTrue($signer->isValid($signed));
        $clock->set(1061);
        self::assertFalse($signer->isValid($signed));
    }

    public function testAnotherKeyCannotValidate(): void
    {
        $signed = (new SignedUrlGenerator($this->urls(), 'chave-a'))->sign('api.v1.orders.show', ['id' => 7]);

        self::assertFalse((new SignedUrlGenerator($this->urls(), 'chave-b'))->isValid($signed));
    }

    /** @return array<string,array{list<string>,int}> */
    public static function conflictScenarios(): array
    {
        return [
            'genérica antes da específica esconde a segunda' => [['/users/{id}', '/users/{slug:[a-z]+}'], 1],
            'placeholder livre cobre um literal' => [['/{recurso}/{id}', '/users/{id}'], 1],
            'constraint que casa o literal cobre a rota' => [['/{tipo:users|posts}/{id}', '/users/{id}'], 1],
            'constraint que não casa o literal não cobre' => [['/{tipo:posts}/{id}', '/users/{id}'], 0],
            'contagens diferentes nunca colidem' => [['/users/{id}', '/users/{id}/perfil'], 0],
            'específica antes da genérica é legítima' => [['/users/{id:\d+}', '/users/{slug:[a-z]+}'], 0],
            'contagens de segmento diferentes não colidem' => [['/users/{id}', '/users/{id}/posts/{post}'], 0],
            'constraints diferentes não são comparáveis' => [['/a/{x:\d+}', '/a/{y:[a-z]+}'], 0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('conflictScenarios')]
    public function testUnreachableRoutesAreDetectedWithoutFalsePositives(array $paths, int $expected): void
    {
        $dynamic = [];
        foreach ($paths as $index => $path) {
            $pattern = \NeoFramework\Core\Routing\PatternCompiler::compile($path);
            $dynamic[] = ['path' => $path, 'name' => 'r' . $index, 'controller' => 'C', 'action' => 'a'] + $pattern;
        }

        $conflicts = RouteConflictDetector::detect(['static' => [], 'dynamic' => ['GET' => $dynamic], 'named' => []]);

        self::assertCount($expected, $conflicts);
    }

    /** Um opcional muda quantos segmentos a rota casa; comparar contagens deixaria de ser sólido. */
    public function testRoutesWithOptionalSegmentsAreNotJudged(): void
    {
        $dynamic = [];
        foreach (['/posts/{a}', '/posts/{b?}'] as $index => $path) {
            $pattern = \NeoFramework\Core\Routing\PatternCompiler::compile($path);
            $dynamic[] = ['path' => $path, 'name' => 'r' . $index, 'controller' => 'C', 'action' => 'a'] + $pattern;
        }

        self::assertSame([], RouteConflictDetector::detect(['static' => [], 'dynamic' => ['GET' => $dynamic], 'named' => []]));
    }

    public function testOptionsIsAnsweredByTheMatcherWithoutRunningTheController(): void
    {
        $response = TestClient::forControllers([PrefixedControllerFixture::class])->request('OPTIONS', '/api/v1/orders');

        $response->assertStatus(204)->assertHeader('Allow', 'OPTIONS, POST');
    }

    /** Uma rota OPTIONS declarada tem que vencer a resposta automática. */
    public function testADeclaredOptionsRouteStillWins(): void
    {
        TestClient::forControllers([PrefixedControllerFixture::class])
            ->request('OPTIONS', '/api/v1/orders/opcoes')
            ->assertOk()
            ->assertJsonPath('declarada', true);
    }
}
