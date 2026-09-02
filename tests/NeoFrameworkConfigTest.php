<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Commands\Config\ListConfig;
use NeoFramework\Core\Config\AppConfig;
use NeoFramework\Core\Config\ConfigRepository;
use NeoFramework\Core\Config\ConfigRepositoryInterface;
use NeoFramework\Core\Config\ConfigurationException;
use NeoFramework\Core\Config\ConfigValidator;
use NeoFramework\Core\Container;
use PHPUnit\Framework\TestCase;

final class NeoFrameworkConfigTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neof-config-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/Config', 0775, true);
        \NeoFramework\Core\Support\ProjectRoot::set($this->root);
    }

    protected function tearDown(): void
    {
        \NeoFramework\Core\Support\ProjectRoot::set(null);
        $cache = $this->root . '/Cache/config.php';
        if (is_file($cache)) unlink($cache);
        @rmdir($this->root . '/Cache');
        foreach (glob($this->root . '/Config/*.php') ?: [] as $file) unlink($file);
        @rmdir($this->root . '/Config');
        @rmdir($this->root);
    }

    public function testLoadsDotNotationAndReportsRequiredKeys(): void
    {
        file_put_contents($this->root . '/Config/app.php', "<?php return ['environment' => 'test', 'url' => ''];");
        $config = ConfigRepository::fromRoot($this->root, false);

        self::assertSame('test', $config->get('app.environment'));
        self::assertSame('fallback', $config->get('app.name', 'fallback'));
        $this->expectException(ConfigurationException::class);
        $config->require('app.name');
    }

    public function testCachedConfigurationDoesNotReevaluateSourceFiles(): void
    {
        $file = $this->root . '/Config/app.php';
        file_put_contents($file, "<?php return ['environment' => 'test', 'url' => 'first'];");
        $config = ConfigRepository::fromRoot($this->root, false);
        $config->cache();
        file_put_contents($file, "<?php return ['environment' => 'test', 'url' => 'second'];");

        self::assertSame('first', ConfigRepository::fromRoot($this->root)->require('app.url'));
        $config->clearCache();
        self::assertSame('second', ConfigRepository::fromRoot($this->root)->require('app.url'));
    }

    public function testTypedConfigurationRejectsInvalidValues(): void
    {
        $this->expectException(ConfigurationException::class);
        ConfigValidator::validate(['app' => ['environment' => 'staging']]);
    }

    public function testTypedAppConfigurationIsBuiltFromTheRepository(): void
    {
        $config = new ConfigRepository($this->root, ['app' => ['environment' => 'prod', 'url' => 'https://neo.test']], false);
        self::assertTrue(AppConfig::from($config)->isProduction());
    }

    public function testContainerRegistersTheRepositoryAndTypedConfigurations(): void
    {
        Container::reset();
        try {
            $container = new Container();
            self::assertInstanceOf(ConfigRepositoryInterface::class, $container->get(ConfigRepositoryInterface::class));
            self::assertInstanceOf(AppConfig::class, $container->get(AppConfig::class));
        } finally {
            Container::reset();
        }
    }

    public function testContainerRejectsInvalidConfigurationDuringBootstrap(): void
    {
        file_put_contents($this->root . '/Config/app.php', "<?php return ['environment' => 'invalid', 'url' => ''];");
        Container::reset();
        $this->expectException(ConfigurationException::class);
        try {
            (new Container())->get(AppConfig::class);
        } finally {
            Container::reset();
        }
    }

    public function testConfigListRedactsSensitiveValuesRecursively(): void
    {
        self::assertSame(['database' => ['password' => '[redacted]'], 'app' => ['name' => 'Neo']], ListConfig::redact(['database' => ['password' => 'secret'], 'app' => ['name' => 'Neo']]));
    }
}
