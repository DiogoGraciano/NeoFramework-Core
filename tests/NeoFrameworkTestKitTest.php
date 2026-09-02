<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Attributes\RoutePrefix;
use NeoFramework\Core\Middleware\ErrorHandler;
use NeoFramework\Core\Response;
use NeoFramework\Core\Testing\HttpTestCase;
use NeoFramework\Core\Testing\TestClient;
use Psr\Http\Message\ServerRequestInterface;

#[RoutePrefix('/kit')]
final class TestKitControllerFixture extends Controller
{
    #[Route('/users/{id:\\d+}', ['GET'], false, 'kit.users.show')]
    public function show(int $id): Response { return $this->json(['id' => $id, 'name' => 'Ana']); }

    #[Route('/users', ['POST'], false, 'kit.users.store')]
    public function store(ServerRequestInterface $request): Response
    {
        $body = json_decode((string) $request->getBody(), true) ?: [];
        return $this->json(['created' => $body['email'] ?? null], 201)->withHeader('Location', '/kit/users/1');
    }

    #[Route('/users', ['GET'], false, 'kit.users.index')]
    public function index(): Response { return $this->json(['data' => [['id' => 1], ['id' => 2], ['id' => 3]]]); }

    #[Route('/form', ['POST'], false, 'kit.form')]
    public function form(): Response { return $this->json(['email' => $this->request->post('email')]); }

    #[Route('/login', ['GET'], false, 'kit.login')]
    public function login(): Response
    {
        return $this->response->withStatus(204)->withAddedHeader('Set-Cookie', 'session=abc123; Path=/; HttpOnly');
    }

    #[Route('/whoami', ['GET'], false, 'kit.whoami')]
    public function whoami(ServerRequestInterface $request): Response
    {
        return $this->json(['session' => $request->getCookieParams()['session'] ?? null]);
    }

    #[Route('/go', ['GET'], false, 'kit.go')]
    public function go(): Response { return $this->response->go('/kit/users'); }

    #[Route('/boom', ['GET'], false, 'kit.boom')]
    public function boom(): Response { throw new \RuntimeException('explodiu'); }
}

final class NeoFrameworkTestKitTest extends HttpTestCase
{
    protected function controllers(): array { return [TestKitControllerFixture::class]; }

    public function testGetJsonWithPathAssertions(): void
    {
        $this->getJson('/kit/users/42')
            ->assertOk()
            ->assertContentType('application/json')
            ->assertJsonPath('id', 42)
            ->assertJson(['id' => 42, 'name' => 'Ana']);
    }

    public function testJsonCountOnANestedPath(): void
    {
        $this->getJson('/kit/users')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.1.id', 2);
    }

    public function testPostJsonSendsAndParsesABody(): void
    {
        $this->postJson('/kit/users', ['email' => 'ana@example.com'])
            ->assertCreated()
            ->assertJsonPath('created', 'ana@example.com')
            ->assertHeader('Location', '/kit/users/1');
    }

    public function testFormPostReachesTheParsedBody(): void
    {
        $this->post('/kit/form', ['email' => 'form@example.com'])->assertOk()->assertJsonPath('email', 'form@example.com');
    }

    /** OPTIONS entra no Allow porque o matcher passou a respondê-lo sozinho. */
    public function testMethodNotAllowedCarriesTheAllowHeader(): void
    {
        $this->delete('/kit/users/1')->assertMethodNotAllowed()->assertAllows(['GET', 'HEAD', 'OPTIONS']);
    }

    public function testNotFoundNegotiatesJson(): void
    {
        $this->getJson('/kit/inexistente')->assertNotFound()->assertJsonPath('status', 404);
        $this->get('/kit/inexistente')->assertNotFound()->assertContentType('text/plain');
    }

    public function testUncaughtExceptionBecomesA500(): void
    {
        $this->get('/kit/boom')->assertServerError()->assertBodyContains('explodiu');
    }

    public function testRedirectAssertion(): void
    {
        $this->get('/kit/go')->assertRedirect()->assertHeader('Location');
    }

    public function testHeadCarriesNoBody(): void
    {
        $this->head('/kit/users/7')->assertOk()->assertBodySame('');
    }

    /** O cookie jar mantém a sessão entre duas requisições do mesmo cliente. */
    public function testCookieJarCarriesStateAcrossRequests(): void
    {
        $client = $this->client();

        $client->get('/kit/login')->assertNoContent()->assertCookie('session', 'abc123');
        $client->getJson('/kit/whoami')->assertOk()->assertJsonPath('session', 'abc123');
    }

    /** Dois clientes no mesmo processo não compartilham cookies. */
    public function testTwoClientsKeepIndependentCookies(): void
    {
        $logged = TestClient::forControllers([TestKitControllerFixture::class]);
        $anonymous = TestClient::forControllers([TestKitControllerFixture::class]);

        $logged->get('/kit/login');

        $logged->getJson('/kit/whoami')->assertJsonPath('session', 'abc123');
        $anonymous->getJson('/kit/whoami')->assertJsonPath('session', null);
    }

    public function testBasePathIsAppliedToEveryRequest(): void
    {
        $client = TestClient::forControllers([TestKitControllerFixture::class], [ErrorHandler::class])->withBasePath('/app');

        $client->getJson('/kit/users/5')->assertOk()->assertJsonPath('id', 5);
    }

    public function testDefaultHeadersApplyToEveryRequest(): void
    {
        $client = TestClient::forControllers([TestKitControllerFixture::class])->withHeader('Accept', 'application/json');

        $client->get('/kit/inexistente')->assertNotFound()->assertContentType('application/json');
    }
}
