<?php

declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Config\ConfigRepository;
use NeoFramework\Core\Config\StorageConfig;
use NeoFramework\Core\Enums\FileStorageDisk;
use NeoFramework\Core\Storage\DiskInterface;
use NeoFramework\Core\Storage\FilesystemFactory;
use NeoFramework\Core\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

final class NeoFrameworkFilesystemFactoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neof-factory-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0775, true);
        ProjectRoot::set($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/**/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (glob($this->root . '/*') ?: [] as $entry) {
            is_dir($entry) ? @rmdir($entry) : @unlink($entry);
        }
        @rmdir($this->root);
        ProjectRoot::set(null);
    }

    public function testLocalFactoryCreatesAUsableFlysystemDisk(): void
    {
        $disk = FilesystemFactory::create(FileStorageDisk::LOCAL, 'assets', $this->config('local'));

        $disk->write('reports/coverage.txt', 'verificado');

        self::assertTrue($disk->exists('reports/coverage.txt'));
        self::assertSame('verificado', stream_get_contents($disk->readStream('reports/coverage.txt')));
    }

    public function testS3FactoryBuildsTheAdapterWithoutOpeningANetworkConnection(): void
    {
        $disk = FilesystemFactory::create(FileStorageDisk::S3, 'ignored', $this->config('s3'));

        self::assertInstanceOf(DiskInterface::class, $disk);
    }

    private function config(string $disk): StorageConfig
    {
        return StorageConfig::from(new ConfigRepository($this->root, [
            'storage' => [
                'disk' => $disk,
                'root_path' => 'assets',
                's3' => [
                    'client' => ['version' => 'latest', 'region' => 'us-east-1'],
                    'bucket' => 'coverage-contracts',
                ],
            ],
        ], preferCache: false));
    }
}
