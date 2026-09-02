<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Commands\Event\Cache as EventCacheCommand;
use NeoFramework\Core\Commands\Event\Clear as EventClearCommand;
use NeoFramework\Core\Commands\ExitCode;
use NeoFramework\Core\Events\DispatcherFactory;
use NeoFramework\Core\Events\ListenerCache;
use NeoFramework\Core\Events\RequestReceived;
use NeoFramework\Core\Request;
use NeoFramework\Core\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/** Container vazio: o dispatcher tem de se virar com o mapa em disco. */
final class EmptyContainer implements ContainerInterface
{
    public function has(string $id): bool { return false; }

    public function get(string $id): mixed { throw new \RuntimeException("not found: {$id}"); }
}

final class NeoFrameworkListenerCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neof-events-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/Config', 0775, true);
        ProjectRoot::set($this->root);
        ListenerCache::reset();
        RecordingListener::$seen = [];
    }

    protected function tearDown(): void
    {
        ProjectRoot::set(null);
        ListenerCache::reset();
        foreach (['/Cache/events.php', '/Config/events.php'] as $file) {
            if (is_file($this->root . $file)) unlink($this->root . $file);
        }
        @rmdir($this->root . '/Cache');
        @rmdir($this->root . '/Config');
        @rmdir($this->root);
    }

    private function writeConfig(string $body): void
    {
        file_put_contents($this->root . '/Config/events.php', "<?php\n\nreturn {$body};\n");
    }

    public function testWithoutACacheTheProviderStillReadsTheConfigFile(): void
    {
        $this->writeConfig('[\\' . RequestReceived::class . '::class => [\\' . RecordingListener::class . '::class]]');

        self::assertNull(ListenerCache::load());
        self::assertSame(
            [RequestReceived::class => [RecordingListener::class]],
            ListenerCache::provider()->registered(),
        );
    }

    public function testTheCompiledMapIsUsedInsteadOfTheConfigFile(): void
    {
        $this->writeConfig('[\\' . RequestReceived::class . '::class => [\\' . RecordingListener::class . '::class]]');
        self::assertTrue(ListenerCache::store(ListenerCache::build()));

        // Uma vez compilado, o arquivo-fonte deixa de ser lido: é justamente o
        // include e a revalidação por requisição que a compilação elimina.
        unlink($this->root . '/Config/events.php');
        ListenerCache::reset();

        self::assertSame(
            [RequestReceived::class => [RecordingListener::class]],
            ListenerCache::provider()->registered(),
        );
    }

    public function testTheDispatcherBuiltFromAnEmptyContainerUsesTheCompiledMap(): void
    {
        $this->writeConfig('[\\' . RequestReceived::class . '::class => [\\' . RecordingListener::class . '::class]]');
        ListenerCache::store(ListenerCache::build());
        ListenerCache::reset();

        DispatcherFactory::fromContainer(new EmptyContainer())->dispatch(new RequestReceived(new Request(), 'req-1'));

        self::assertCount(1, RecordingListener::$seen);
    }

    public function testCompilingAnUnknownListenerFailsInsteadOfWritingABrokenMap(): void
    {
        $this->writeConfig('[\\' . RequestReceived::class . '::class => [\'Classe\\\\Que\\\\Nao\\\\Existe\']]');

        $status = (new EventCacheCommand())->execute();

        // O comando existe para que um listener inexistente reprove no deploy,
        // e não no meio de uma requisição.
        self::assertSame(ExitCode::FAILURE, $status);
        self::assertFileDoesNotExist($this->root . '/Cache/events.php');
    }

    public function testCacheAndClearRoundTrip(): void
    {
        $this->writeConfig('[\\' . RequestReceived::class . '::class => [\\' . RecordingListener::class . '::class]]');

        self::assertSame(ExitCode::OK, (new EventCacheCommand())->execute());
        self::assertFileExists($this->root . '/Cache/events.php');

        self::assertSame(ExitCode::OK, (new EventClearCommand())->execute());
        self::assertFileDoesNotExist($this->root . '/Cache/events.php');
        self::assertNull(ListenerCache::load());
    }

    public function testCompilingWithoutAConfigFileWritesAnEmptyMap(): void
    {
        self::assertSame(ExitCode::OK, (new EventCacheCommand())->execute());
        self::assertSame([], ListenerCache::load());
    }

    public function testPrioritySurvivesTheWriteAndReadRoundTrip(): void
    {
        $this->writeConfig(
            '[\\' . RequestReceived::class . '::class => ['
            . '\\' . RecordingListener::class . '::class, '
            . '[\\' . SecondRecordingListener::class . '::class, 10]'
            . ']]'
        );
        ListenerCache::store(ListenerCache::build());
        ListenerCache::reset();

        self::assertSame(
            [RequestReceived::class => [SecondRecordingListener::class, RecordingListener::class]],
            ListenerCache::provider()->registered(),
        );
    }
}
