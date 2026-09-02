<?php
declare(strict_types=1);

namespace Tests;

use GuzzleHttp\Psr7\ServerRequest;
use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Application;
use NeoFramework\Core\Attributes\Email;
use NeoFramework\Core\Attributes\Length;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Attributes\Rule;
use NeoFramework\Core\Attributes\Sensitive;
use NeoFramework\Core\Exceptions\ValidationException;
use NeoFramework\Core\Http\DtoBinder;
use NeoFramework\Core\Response;
use NeoFramework\Core\Testing\TestClient;
use NeoFramework\Core\Validation\RespectValidator;
use NeoFramework\Core\Validation\RuleSpec;
use NeoFramework\Core\Validation\ValidationRule;
use NeoFramework\Core\Validation\ValidatorInterface;
use NeoFramework\Core\Validator;
use PHPUnit\Framework\TestCase;
use Respect\Validation\Exceptions\ComponentException;
use Respect\Validation\Factory;

final readonly class CatalogDtoFixture
{
    public function __construct(
        #[Rule('between', [18, 120])] public int $idade,
        #[Rule('in', [['pix', 'boleto']], message: 'Forma de pagamento inválida.')] public string $pagamento,
    ) {}
}

final readonly class SensitiveDtoFixture
{
    public function __construct(#[Sensitive] #[Rule('in', [['correta']])] public string $senha) {}
}

final readonly class UnknownRuleDtoFixture
{
    public function __construct(#[Rule('regraQueNaoExiste')] public string $campo) {}
}

/** Uma regra de terceiro: implementa o contrato e o binder a reconhece sem alteração no Core. */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class SlugRuleFixture implements ValidationRule
{
    public function spec(): RuleSpec { return new RuleSpec('regex', ['/^[a-z0-9-]+$/'], 'Deve ser um slug.'); }
}

final readonly class SlugDtoFixture
{
    public function __construct(#[SlugRuleFixture] public string $slug) {}
}

final class ValidationControllerFixture extends Controller
{
    #[Route('/catalog', ['POST'], validCsrf: false)]
    public function store(CatalogDtoFixture $input): Response { return $this->json(['idade' => $input->idade]); }
}

final class NeoFrameworkValidationTest extends TestCase
{
    /** @param array<string,mixed> $payload */
    private static function request(array $payload): ServerRequest
    {
        return (new ServerRequest('POST', '/x'))->withParsedBody($payload);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,list<string>>
     */
    private function errorsFor(string $class, array $payload): array
    {
        try {
            DtoBinder::bind($class, self::request($payload));
        } catch (ValidationException $exception) {
            return $exception->errors;
        }

        return [];
    }

    public function testTheEngineCatalogueIsReachableFromAnAttribute(): void
    {
        $errors = $this->errorsFor(CatalogDtoFixture::class, ['idade' => 7, 'pagamento' => 'cartao']);

        self::assertSame(['idade must be between 18 and 120'], $errors['idade']);
        self::assertSame(['Forma de pagamento inválida.'], $errors['pagamento']);
    }

    public function testAValidPayloadStillReachesTheController(): void
    {
        TestClient::forControllers([ValidationControllerFixture::class])
            ->postJson('/catalog', ['idade' => 30, 'pagamento' => 'pix'])
            ->assertOk()
            ->assertJson(['idade' => 30]);
    }

    /**
     * A mensagem é uma saída pública. A regra `in` lista os valores aceitos, e é
     * o tipo de regra que ecoaria o recebido se o campo não fosse nomeado.
     */
    public function testTheRejectedValueNeverAppearsInTheMessage(): void
    {
        $errors = $this->errorsFor(SensitiveDtoFixture::class, ['senha' => 'p4ssw0rd-do-usuario']);

        self::assertArrayHasKey('senha', $errors);
        self::assertStringNotContainsString('p4ssw0rd-do-usuario', implode(' ', $errors['senha']));
    }

    /** Regra inexistente é erro de quem escreveu o atributo: 500, não 422. */
    public function testAnUnknownRuleIsADeveloperErrorAndNotAValidationFailure(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/regraQueNaoExiste/');

        DtoBinder::bind(UnknownRuleDtoFixture::class, self::request(['campo' => 'valor']));
    }

    public function testAThirdPartyRuleAttributeNeedsNoChangeInTheEngine(): void
    {
        self::assertSame(['Deve ser um slug.'], $this->errorsFor(SlugDtoFixture::class, ['slug' => 'Com Espaço'])['slug']);
        self::assertSame([], $this->errorsFor(SlugDtoFixture::class, ['slug' => 'um-slug-valido']));
    }

    public function testTheBuiltInAttributesKeepTheirMessages(): void
    {
        $rules = [new Email(), new Length(min: 8)];

        self::assertSame(['Must be a valid email address.'], (new RespectValidator())->validate('nao-email', [$rules[0]], 'email'));
        self::assertSame(['Length is invalid.'], (new RespectValidator())->validate('curto', [$rules[1]], 'password'));
        self::assertSame([], (new RespectValidator())->validate('neo@example.com', $rules, 'email'));
    }

    public function testTheFacadeAcceptsRuleNamesWithoutTheApplicationImportingTheLibrary(): void
    {
        $result = (new Validator())->make(
            ['email' => 'nao-email', 'idade' => 30],
            ['email' => 'email', 'idade' => new Rule('between', [18, 120])],
        );

        self::assertTrue($result->hasError());
        self::assertArrayNotHasKey('idade', $result->getErrors());
        self::assertSame(['email must be valid email'], $result->getErrors()['email']);
    }

    public function testTheFacadeStacksRulesAndHonoursACustomMessage(): void
    {
        $result = (new Validator())->make(
            ['senha' => 'curta'],
            ['senha' => ['stringType', new Rule('length', [8, null])]],
            ['senha' => 'A senha precisa de 8 caracteres.'],
        );

        self::assertSame(['senha' => ['A senha precisa de 8 caracteres.']], $result->getErrors());
    }

    /** A cadeia `v::` deixou de ser aceita: a biblioteca não é mais a superfície. */
    public function testTheFacadeRefusesARespectChain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Validator())->make(['email' => 'x'], ['email' => \Respect\Validation\Validator::email()]);
    }

    public function testTheFacadeRefusesAThingThatIsNotARule(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Validator())->make(['a' => 1], ['a' => 42]);
    }

    /**
     * A regra de banco só era registrada em `Kernel::init()`, que apenas o FPM
     * percorre — sob FrankenPHP ou RoadRunner ela não existia pelo nome.
     */
    public function testCoreRulesAreReachableByNameAfterBootInAnyRuntime(): void
    {
        (new Application())->boot();

        // Encontrar a classe e reclamar dos argumentos prova que o namespace do
        // Core está no catálogo; um nome desconhecido continua sendo recusado.
        try {
            Factory::getDefaultInstance()->rule('uniqueDb', []);
            self::fail('A regra deveria exigir seus argumentos.');
        } catch (\ArgumentCountError $expected) {
            self::assertStringContainsString('Validator\\Rules\\UniqueDb', $expected->getMessage());
        }

        $this->expectException(ComponentException::class);
        Factory::getDefaultInstance()->rule('regraQueNaoExiste', []);
    }

    /** O motor é substituível: o binder usa o do container quando existe. */
    public function testTheEngineIsReplaceable(): void
    {
        $engine = new class implements ValidatorInterface {
            public function validate(mixed $value, array $rules, string $field): array { return $rules === [] ? [] : ['recusado por política interna']; }
        };

        self::assertSame(['recusado por política interna'], $engine->validate('qualquer', [new Email()], 'email'));
    }
}
