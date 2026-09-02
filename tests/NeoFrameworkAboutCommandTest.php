<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Commands\About;
use NeoFramework\Core\Config\ConfigRepository;
use PHPUnit\Framework\TestCase;

final class NeoFrameworkAboutCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neof-about-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/Cache', 0775, true);
        file_put_contents($this->root . '/Cache/config.php', '<?php return [];');
        file_put_contents($this->root . '/Cache/routes.php', '<?php return [];');
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/Cache/config.php');
        @unlink($this->root . '/Cache/routes.php');
        @rmdir($this->root . '/Cache');
        @rmdir($this->root);
    }

    public function testItReportsOperationalStateWithoutConfigurationValues(): void
    {
        $config = new ConfigRepository($this->root, [
            'app' => ['environment' => 'prod', 'url' => 'https://secret.example'],
            'cache' => ['adapter' => 'filesystem'],
            'logging' => ['stream' => 'stderr'],
        ], false);

        $details = About::details($config, $this->root, dirname(__DIR__));

        self::assertSame('prod', $details['environment']);
        self::assertSame('enabled', $details['config_cache']);
        self::assertSame('enabled', $details['route_cache']);
        self::assertSame('stderr', $details['log_stream']);
        self::assertStringNotContainsString('secret.example', json_encode($details, JSON_THROW_ON_ERROR));
    }
}
