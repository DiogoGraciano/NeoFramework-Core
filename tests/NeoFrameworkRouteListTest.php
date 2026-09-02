<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Attributes\RoutePrefix;
use NeoFramework\Core\Commands\Route\ListRoutes;
use NeoFramework\Core\Response;
use NeoFramework\Core\Routing\AttributeLoader;
use NeoFramework\Core\Routing\RouteCompiler;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[RoutePrefix('/inventory')]
final class RouteListControllerFixture extends Controller
{
    #[Route('/items', ['GET'], false, 'inventory.index')]
    public function index(): Response { return $this->json([]); }

    #[Route('/items/{id:\\d+}', ['GET', 'PUT', 'DELETE'], false, 'inventory.show')]
    public function show(int $id): Response { return $this->json(['id' => $id]); }

    #[Route('/items/{slug}/{page?}', ['GET'], false, 'inventory.paged')]
    public function paged(string $slug, ?string $page = null): Response { return $this->json([]); }
}

final class NeoFrameworkRouteListTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        $map = RouteCompiler::compile((new AttributeLoader())->load([RouteListControllerFixture::class]));

        return (new ReflectionMethod(ListRoutes::class, 'flatten'))->invoke(null, $map);
    }

    /** Uma rota com três verbos é uma linha, não três. */
    public function testMethodsAreCollapsedIntoASingleRow(): void
    {
        $rows = $this->rows();
        self::assertCount(3, $rows);

        $show = array_values(array_filter($rows, static fn (array $r): bool => $r['name'] === 'inventory.show'));
        self::assertCount(1, $show);
        self::assertSame('DELETE|GET|PUT', $show[0]['methods']);
    }

    /** HEAD acompanha todo GET; listá-lo só polui a tabela. */
    public function testHeadIsNotListed(): void
    {
        foreach ($this->rows() as $row) {
            self::assertStringNotContainsString('HEAD', (string) $row['methods']);
        }
    }

    public function testRowsCarryPathNameAndAction(): void
    {
        $rows = $this->rows();
        $index = array_values(array_filter($rows, static fn (array $r): bool => $r['name'] === 'inventory.index'))[0];

        self::assertSame('/inventory/items', $index['path']);
        self::assertSame('GET', $index['methods']);
        self::assertSame('RouteListControllerFixture::index', $index['action']);
    }

    public function testRowsAreSortedByPath(): void
    {
        $paths = array_column($this->rows(), 'path');
        $sorted = $paths;
        sort($sorted);

        self::assertSame($sorted, $paths);
    }

    public function testFilterByMethodAndPath(): void
    {
        $filter = new ReflectionMethod(ListRoutes::class, 'filter');
        $rows = $this->rows();

        self::assertCount(1, $filter->invoke(null, $rows, 'PUT', null));
        self::assertCount(3, $filter->invoke(null, $rows, 'GET', null));
        self::assertCount(0, $filter->invoke(null, $rows, 'PATCH', null));
        self::assertCount(2, $filter->invoke(null, $rows, null, '/items/'));
        self::assertCount(3, $filter->invoke(null, $rows, null, null));
    }
}
