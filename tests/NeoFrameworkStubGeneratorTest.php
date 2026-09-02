<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Support\StubGenerator;
use NeoFramework\Core\Support\StubPublisher;
use PHPUnit\Framework\TestCase;

final class NeoFrameworkStubGeneratorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neof-stubs-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void { $this->remove($this->root); }

    /** @return array{directory:string,namespace:string,suffix:string,stub:string} */
    private function controller(): array
    {
        return ['directory' => 'App/Controllers', 'namespace' => 'App\\Controllers', 'suffix' => 'Controller', 'stub' => 'controller'];
    }

    public function testItCreatesNamespacedClassWithoutOverwritingIt(): void
    {
        $generator = new StubGenerator($this->root, dirname(__DIR__));
        $created = $generator->make('Admin/UserController', $this->controller());

        self::assertSame('created', $created['status']);
        self::assertSame('App\\Controllers\\Admin\\UserController', $created['class']);
        self::assertStringContainsString('namespace App\\Controllers\\Admin;', (string) file_get_contents($created['path']));
        self::assertSame('exists', $generator->make('Admin/UserController', $this->controller())['status']);
    }

    public function testProjectStubWinsAndForceAllowsAnExplicitOverwrite(): void
    {
        mkdir($this->root . '/resources/stubs', 0775, true);
        file_put_contents($this->root . '/resources/stubs/controller.stub', "<?php namespace {{ namespace }}; final class {{ class }} {}\n");
        $generator = new StubGenerator($this->root, dirname(__DIR__));
        $created = $generator->make('Report', $this->controller());

        self::assertStringContainsString('final class ReportController {}', (string) file_get_contents($created['path']));
        file_put_contents($created['path'], 'old');
        self::assertSame('created', $generator->make('Report', $this->controller(), force: true)['status']);
        self::assertStringNotContainsString('old', (string) file_get_contents($created['path']));
    }

    public function testItPublishesStubsWithoutReplacingProjectCustomizations(): void
    {
        $publisher = new StubPublisher($this->root, dirname(__DIR__));
        $first = $publisher->publish();
        self::assertGreaterThan(0, $first['created']);
        file_put_contents($this->root . '/resources/stubs/controller.stub', 'custom');
        $second = $publisher->publish();
        self::assertGreaterThan(0, $second['exists']);
        self::assertSame('custom', file_get_contents($this->root . '/resources/stubs/controller.stub'));
        $publisher->publish(force: true);
        self::assertStringContainsString('{{ class }}', (string) file_get_contents($this->root . '/resources/stubs/controller.stub'));
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($child) ? $this->remove($child) : unlink($child);
        }
        rmdir($path);
    }
}
