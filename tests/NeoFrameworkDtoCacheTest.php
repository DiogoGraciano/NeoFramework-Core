<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Attributes\FromHeader;
use NeoFramework\Core\Attributes\FromQuery;
use NeoFramework\Core\Attributes\Length;
use NeoFramework\Core\Attributes\ListOf;
use NeoFramework\Core\Attributes\Sensitive;
use NeoFramework\Core\Http\DtoBinder;
use NeoFramework\Core\Http\DtoCache;
use NeoFramework\Core\Http\DtoMetadata;
use NeoFramework\Core\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

enum CachedRole: string
{
    case Admin = 'admin';
    case User = 'user';
}

final readonly class CachedTag
{
    public function __construct(public string $label) {}
}

final readonly class CachedInput
{
    /** @param list<CachedTag> $tags */
    public function __construct(
        #[Length(min: 3)] public string $name,
        #[FromQuery('p')] public int $page = 1,
        #[FromHeader('X-Trace')] public ?string $trace = null,
        #[Sensitive] public string $token = '',
        #[ListOf(CachedTag::class)] public array $tags = [],
        public CachedRole $role = CachedRole::User,
    ) {}
}

/** Default que `var_export` não reproduz: a classe não pode ser compilada. */
final readonly class UncompilableInput
{
    public function __construct(public object $marker = new \stdClass()) {}
}

final class NeoFrameworkDtoCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neof-dto-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0775, true);
        ProjectRoot::set($this->root);
        DtoCache::reset();
        DtoMetadata::reset();
    }

    protected function tearDown(): void
    {
        ProjectRoot::set(null);
        DtoCache::reset();
        DtoMetadata::reset();
        if (is_file($this->root . '/Cache/dto.php')) unlink($this->root . '/Cache/dto.php');
        @rmdir($this->root . '/Cache');
        @rmdir($this->root);
    }

    public function testThePlanCarriesEverythingTheBinderUsedToReadByReflection(): void
    {
        $plan = DtoMetadata::of(CachedInput::class);
        $by = [];
        foreach ($plan as $parameter) $by[$parameter->name] = $parameter;

        self::assertSame('string', $by['name']->type);
        self::assertCount(1, $by['name']->rules);

        self::assertSame('query', $by['page']->source);
        self::assertSame('p', $by['page']->sourceKey);
        self::assertTrue($by['page']->hasDefault);
        self::assertSame(1, $by['page']->default);

        self::assertSame('header', $by['trace']->source);
        self::assertSame('X-Trace', $by['trace']->sourceKey);
        self::assertTrue($by['trace']->allowsNull);

        self::assertTrue($by['token']->sensitive);
        self::assertSame(CachedTag::class, $by['tags']->listElementType);
        self::assertSame(CachedRole::User, $by['role']->default);
    }

    public function testTheCompiledSpecRebuildsAnIdenticalPlan(): void
    {
        $fromReflection = DtoMetadata::of(CachedInput::class);
        $spec = DtoMetadata::compile(CachedInput::class);
        self::assertNotNull($spec);

        // Passa por var_export e volta: é exatamente o que o arquivo em disco faz,
        // e um plano que não sobrevive a essa ida e volta compilaria errado.
        $roundTripped = eval('return ' . var_export([CachedInput::class => $spec], true) . ';');

        DtoMetadata::reset();
        DtoMetadata::preload($roundTripped);

        self::assertEquals($fromReflection, DtoMetadata::of(CachedInput::class));
    }

    public function testAClassWithANonExportableDefaultIsRefusedInsteadOfCompiledWrong(): void
    {
        // Devolver um mapa que reconstrói o DTO com o default errado seria pior
        // do que não compilar a classe.
        self::assertNull(DtoMetadata::compile(UncompilableInput::class));
    }

    public function testStoreAndLoadRoundTrip(): void
    {
        $spec = DtoMetadata::compile(CachedInput::class);
        self::assertNotNull($spec);

        self::assertTrue(DtoCache::store([CachedInput::class => $spec]));
        DtoCache::reset();

        $loaded = DtoCache::load();
        self::assertIsArray($loaded);
        self::assertArrayHasKey(CachedInput::class, $loaded);
    }

    public function testTheCompiledMapIsUsedWithoutTouchingReflection(): void
    {
        $spec = DtoMetadata::compile(CachedInput::class);
        self::assertNotNull($spec);
        DtoCache::store([CachedInput::class => $spec]);

        DtoCache::reset();
        DtoMetadata::reset();

        // Sem `of()` ter construído nada por Reflection nesta instância, o plano
        // já tem de estar completo — é o que prova que o cache foi consumido.
        $plan = DtoMetadata::of(CachedInput::class);
        self::assertCount(6, $plan);
        self::assertSame('p', $plan[1]->sourceKey);
    }

    public function testClearRemovesTheFile(): void
    {
        DtoCache::store([CachedInput::class => DtoMetadata::compile(CachedInput::class)]);
        self::assertFileExists($this->root . '/Cache/dto.php');

        self::assertTrue(DtoCache::clear());
        self::assertFileDoesNotExist($this->root . '/Cache/dto.php');
        self::assertNull(DtoCache::load());
    }

    public function testDiscoveryFollowsNestedDtosAndListElements(): void
    {
        $found = [];
        $collect = (new \ReflectionMethod(DtoCache::class, 'collect'));
        $collect->invokeArgs(null, [CachedInput::class, &$found]);

        // Um DTO aninhado que ficasse de fora continuaria pagando Reflection
        // dentro de um bind que se acredita compilado.
        self::assertArrayHasKey(CachedInput::class, $found);
        self::assertArrayHasKey(CachedTag::class, $found);
    }

    public function testBindingStillWorksThroughTheCompiledPlan(): void
    {
        DtoCache::store([CachedInput::class => DtoMetadata::compile(CachedInput::class)]);
        DtoCache::reset();
        DtoMetadata::reset();

        // O ServerRequest do Guzzle não deriva query params da URI: quem faz isso
        // é o fromGlobals. Aqui a origem precisa ser posta explicitamente.
        $request = (new \GuzzleHttp\Psr7\ServerRequest('POST', '/x?p=7', ['X-Trace' => 'abc']))
            ->withQueryParams(['p' => '7'])
            ->withParsedBody(['name' => 'Diogo']);

        $dto = DtoBinder::bind(CachedInput::class, $request);

        self::assertInstanceOf(CachedInput::class, $dto);
        self::assertSame('Diogo', $dto->name);
        self::assertSame(7, $dto->page);
        self::assertSame('abc', $dto->trace);
    }
}
