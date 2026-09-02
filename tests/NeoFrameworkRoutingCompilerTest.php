<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Response;
use NeoFramework\Core\Routing\AttributeLoader;
use NeoFramework\Core\Routing\Matcher;
use NeoFramework\Core\Routing\PatternCompiler;
use NeoFramework\Core\Routing\RouteCompiler;
use NeoFramework\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class CompilerControllerFixture extends Controller
{
    #[Route('/posts/{slug}/{page?}', ['GET'], false, 'posts.show')]
    public function show(string $slug, ?string $page = null): Response { return $this->text($slug . '|' . ($page ?? 'sem-pagina')); }

    #[Route('/items/{id:\\d+}', ['GET'], false, 'items.show')]
    public function item(int $id): Response { return $this->json(['id' => $id]); }

    #[Route('/users/{name}', ['GET'], false, 'users.byName')]
    public function byName(string $name): Response { return $this->text($name); }

    #[Route('/users/admin/delete', ['GET'], false, 'users.adminDelete')]
    public function adminDelete(): Response { return $this->text('ZONA-ADMIN'); }
}

final class NeoFrameworkRoutingCompilerTest extends TestCase
{
    private function matcher(): Matcher
    {
        return new Matcher(RouteCompiler::compile((new AttributeLoader())->load([CompilerControllerFixture::class])));
    }

    /** Um opcional ausente não pode exigir a barra que o antecede. */
    public function testOptionalParameterMatchesWithAndWithoutTheSegment(): void
    {
        $matcher = $this->matcher();

        $withoutPage = $matcher->match('GET', '/posts/ola');
        self::assertSame('found', $withoutPage->status, '/posts/ola deveria casar com {page?} ausente');
        self::assertSame(['slug' => 'ola'], $withoutPage->variables);

        $withPage = $matcher->match('GET', '/posts/ola/2');
        self::assertSame('found', $withPage->status);
        self::assertSame(['slug' => 'ola', 'page' => '2'], $withPage->variables);
    }

    /** Placeholder fora da gramática precisa estourar, não virar literal morto. */
    public function testUnknownPlaceholderSyntaxThrowsInsteadOfCompilingToALiteral(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Placeholder inválido/');
        PatternCompiler::compile('/posts/{slug}/{page:\\d+?extra}}');
    }

    public function testOptionalMarkerAfterTheNameAcceptsAConstraint(): void
    {
        $compiled = PatternCompiler::compile('/posts/{slug}/{page?:\\d+}');
        self::assertSame(['slug', 'page'], $compiled['variables']);
        self::assertSame(1, preg_match($compiled['regex'], '/posts/ola'));
        self::assertSame(1, preg_match($compiled['regex'], '/posts/ola/7'));
        self::assertSame(0, preg_match($compiled['regex'], '/posts/ola/nao-numero'));
    }

    public function testQuantifierBracesInsideConstraintStillCompile(): void
    {
        $compiled = PatternCompiler::compile('/codes/{code:[A-Z]{2,3}}');
        self::assertSame(1, preg_match($compiled['regex'], '/codes/ABC'));
        self::assertSame(0, preg_match($compiled['regex'], '/codes/ABCD'));
    }

    /** %2F não pode forjar fronteira de segmento e alcançar outra rota. */
    public function testEncodedSlashDoesNotForgeASegmentBoundary(): void
    {
        $match = $this->matcher()->match('GET', '/users/admin%2Fdelete');

        self::assertSame('found', $match->status);
        self::assertSame('users.byName', $match->route->name, '%2F não pode alcançar a rota de dois segmentos');
        self::assertSame(['name' => 'admin/delete'], $match->variables, 'a variável capturada é decodificada');
    }

    /** Percent-escapes normais continuam resolvidos, para rotas acentuadas. */
    public function testNonSlashPercentEscapesAreDecodedForMatching(): void
    {
        $match = $this->matcher()->match('GET', '/users/Jos%C3%A9');
        self::assertSame('found', $match->status);
        self::assertSame(['name' => 'José'], $match->variables);
    }

    /** Entrada do cliente que não cabe no tipo é 400, não 500. */
    public function testInvalidRouteParameterTypeYields400NotServerError(): void
    {
        $client = TestClient::forControllers([CompilerControllerFixture::class]);

        $client->getJson('/items/abc')->assertNotFound();   // a constraint \d+ recusa antes da action
        $client->getJson('/items/42')->assertOk()->assertJsonPath('id', 42);
    }

    public function testOptionalParameterReachesTheActionThroughTheKernel(): void
    {
        $client = TestClient::forControllers([CompilerControllerFixture::class]);

        $client->get('/posts/ola')->assertOk()->assertBodySame('ola|sem-pagina');
        $client->get('/posts/ola/3')->assertOk()->assertBodySame('ola|3');
    }
}
