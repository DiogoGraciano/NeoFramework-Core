<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\FromBody;
use NeoFramework\Core\Attributes\FromHeader;
use NeoFramework\Core\Attributes\FromQuery;
use NeoFramework\Core\Attributes\FromRoute;
use NeoFramework\Core\Attributes\Length;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Attributes\Sensitive;
use NeoFramework\Core\Http\DtoBinder;
use NeoFramework\Core\Http\RequestScope;
use NeoFramework\Core\Http\RequestScopeContext;
use NeoFramework\Core\Logger;
use NeoFramework\Core\Response;
use NeoFramework\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Um DTO que puxa cada campo de uma origem diferente. */
final readonly class MixedSourceDtoFixture
{
    public function __construct(
        #[FromRoute] public int $id,
        #[FromQuery] public string $tab,
        #[FromBody] public string $title,
        #[FromHeader('X-Tenant')] public string $tenant,
        #[FromQuery('sort_by')] public ?string $sortBy = null,
    ) {}
}

final readonly class SecretDtoFixture
{
    public function __construct(
        public string $email,
        #[Sensitive] #[Length(min: 8)] public string $recoveryAnswer,
    ) {}
}

final class DtoSourcesControllerFixture extends Controller
{
    #[Route('/orgs/{id:\\d+}/posts', ['POST'], false)]
    public function store(MixedSourceDtoFixture $input): Response
    {
        return $this->json([
            'id' => $input->id, 'tab' => $input->tab, 'title' => $input->title,
            'tenant' => $input->tenant, 'sortBy' => $input->sortBy,
        ]);
    }

    #[Route('/secrets', ['POST'], false)]
    public function secret(SecretDtoFixture $input): Response { return $this->json(['email' => $input->email]); }
}

final class NeoFrameworkDtoSourcesTest extends TestCase
{
    private function client(): TestClient
    {
        return TestClient::forControllers([DtoSourcesControllerFixture::class]);
    }

    public function testEachFieldComesFromItsDeclaredSource(): void
    {
        $this->client()
            ->postJson('/orgs/7/posts?tab=drafts&sort_by=date', ['title' => 'Olá'], ['X-Tenant' => 'acme'])
            ->assertOk()
            ->assertJsonPath('id', 7)          // FromRoute
            ->assertJsonPath('tab', 'drafts')  // FromQuery
            ->assertJsonPath('title', 'Olá')   // FromBody
            ->assertJsonPath('tenant', 'acme') // FromHeader
            ->assertJsonPath('sortBy', 'date');// FromQuery com chave explícita
    }

    /** Sem o atributo, o campo não seria encontrado na origem certa. */
    public function testRouteVariableIsNotReachableWithoutTheAttribute(): void
    {
        // 'id' só existe na rota; se #[FromRoute] fosse inerte, o bind falharia.
        $this->client()
            ->postJson('/orgs/99/posts?tab=x', ['title' => 't'], ['X-Tenant' => 'acme'])
            ->assertOk()
            ->assertJsonPath('id', 99);
    }

    public function testMissingHeaderProducesAValidationError(): void
    {
        $this->client()
            ->postJson('/orgs/7/posts?tab=x', ['title' => 't'])
            ->assertStatus(422)
            ->assertContentType('application/problem+json');
    }

    /** O valor recusado nunca pode aparecer na resposta de erro. */
    public function testSensitiveValueIsNotEchoedInTheValidationError(): void
    {
        $response = $this->client()->postJson('/secrets', ['email' => 'a@b.com', 'recoveryAnswer' => 'curta']);

        $response->assertStatus(422);
        self::assertStringNotContainsString('curta', $response->body(), 'o valor recusado vazou na resposta');
    }

    /** #[Sensitive] registra o nome no escopo, e o Logger passa a redigi-lo. */
    public function testSensitiveFieldIsRedactedByTheLogger(): void
    {
        $scope = new RequestScope();
        RequestScopeContext::enter($scope);

        try {
            $request = (new \NeoFramework\Core\Request('POST', '/secrets'))
                ->withParsedBody(['email' => 'a@b.com', 'recoveryAnswer' => 'resposta-secreta']);

            DtoBinder::bind(SecretDtoFixture::class, $request);

            self::assertContains('recoveryAnswer', $scope->get(DtoBinder::SENSITIVE_KEYS, []));

            $redact = new \ReflectionMethod(Logger::class, 'redact');
            $redacted = $redact->invoke(null, ['recoveryAnswer' => 'resposta-secreta', 'email' => 'a@b.com']);

            self::assertSame('[redacted]', $redacted['recoveryAnswer']);
            self::assertSame('a@b.com', $redacted['email'], 'campo não sensível não deve ser redigido');
        } finally {
            RequestScopeContext::leave($scope);
        }
    }

    /** Sem o escopo registrado, o nome próprio não seria coberto pelo regex padrão. */
    public function testUnregisteredCustomFieldIsNotRedacted(): void
    {
        $redact = new \ReflectionMethod(Logger::class, 'redact');

        self::assertSame('resposta-secreta', $redact->invoke(null, ['recoveryAnswer' => 'resposta-secreta'])['recoveryAnswer']);
    }
}
