<?php
declare(strict_types=1);

namespace Tests;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use NeoFramework\Core\Storage\DiskInterface;
use NeoFramework\Core\Storage\FlysystemDisk;
use NeoFramework\Core\Storage\InMemoryDisk;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O mesmo contrato para todos os discos.
 *
 * Um comportamento que só vale no disco local é uma armadilha esperando o deploy
 * que troca para S3. O disco S3 entra aqui quando as credenciais existem no
 * ambiente; sem elas o caso se marca como pulado em vez de mentir que passou.
 */
final class NeoFrameworkDiskContractTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryRoots as $root) {
            foreach (glob($root . '/**/*') ?: [] as $file) if (is_file($file)) unlink($file);
            foreach (glob($root . '/*') ?: [] as $entry) is_dir($entry) ? @rmdir($entry) : @unlink($entry);
            @rmdir($root);
        }
    }

    /** @return array<string,array{string}> */
    public static function disks(): array
    {
        return ['memória' => ['memory'], 'local' => ['local'], 's3' => ['s3']];
    }

    private function disk(string $kind): DiskInterface
    {
        return match ($kind) {
            'memory' => new InMemoryDisk(),
            'local' => $this->localDisk(),
            's3' => $this->s3Disk(),
            default => self::fail("Disco desconhecido: {$kind}"),
        };
    }

    private function localDisk(): DiskInterface
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neof-disk-' . bin2hex(random_bytes(6));
        mkdir($root, 0775, true);
        $this->temporaryRoots[] = $root;

        return new FlysystemDisk(new Filesystem(new LocalFilesystemAdapter($root)));
    }

    private function s3Disk(): DiskInterface
    {
        $bucket = (string) (env('AWS_BUCKETNAME') ?: '');
        if ($bucket === '') self::markTestSkipped('AWS_BUCKETNAME não está configurado.');
        if (!class_exists(\Aws\S3\S3Client::class)) self::markTestSkipped('O SDK da AWS não está instalado.');

        $options = ['version' => 'latest', 'region' => (string) (env('AWS_REGION') ?: 'us-east-1')];
        if ((string) (env('AWS_ENDPOINT') ?: '') !== '') {
            // MinIO não faz virtual-host style: sem `use_path_style_endpoint` o
            // SDK monta bucket.minio:9000 e o DNS do compose não resolve.
            $options['endpoint'] = (string) env('AWS_ENDPOINT');
            $options['use_path_style_endpoint'] = true;
            $options['credentials'] = ['key' => (string) env('AWS_KEY'), 'secret' => (string) env('AWS_SECRET')];
        }

        try {
            $client = new \Aws\S3\S3Client($options);
            if (!$client->doesBucketExist($bucket)) $client->createBucket(['Bucket' => $bucket]);
        } catch (\Throwable $error) {
            self::markTestSkipped('S3 indisponível: ' . $error->getMessage());
        }

        return new FlysystemDisk(new Filesystem(new \League\Flysystem\AwsS3V3\AwsS3V3Adapter($client, $bucket)));
    }

    #[DataProvider('disks')]
    public function testWriteReadDeleteRoundTrip(string $kind): void
    {
        $disk = $this->disk($kind);
        $path = 'contract/' . bin2hex(random_bytes(4)) . '.txt';

        self::assertFalse($disk->exists($path));

        $disk->write($path, 'conteúdo');
        self::assertTrue($disk->exists($path));
        self::assertSame('conteúdo', stream_get_contents($disk->readStream($path)));

        $disk->delete($path);
        self::assertFalse($disk->exists($path));
    }

    #[DataProvider('disks')]
    public function testWriteStreamStoresExactlyWhatTheStreamCarried(string $kind): void
    {
        $disk = $this->disk($kind);
        $path = 'contract/' . bin2hex(random_bytes(4)) . '.bin';
        $contents = random_bytes(64_000);

        $source = fopen('php://temp', 'r+b');
        self::assertIsResource($source);
        fwrite($source, $contents);
        rewind($source);

        $disk->writeStream($path, $source);

        self::assertSame($contents, stream_get_contents($disk->readStream($path)));
        $disk->delete($path);
    }

    #[DataProvider('disks')]
    public function testWriteStreamOverwritesAnExistingPath(string $kind): void
    {
        $disk = $this->disk($kind);
        $path = 'contract/' . bin2hex(random_bytes(4)) . '.txt';

        $disk->write($path, 'antigo');

        $source = fopen('php://temp', 'r+b');
        self::assertIsResource($source);
        fwrite($source, 'novo');
        rewind($source);
        $disk->writeStream($path, $source);

        self::assertSame('novo', stream_get_contents($disk->readStream($path)));
        $disk->delete($path);
    }

    #[DataProvider('disks')]
    public function testReadingSomethingThatIsNotThereFails(string $kind): void
    {
        $this->expectException(\Throwable::class);
        $this->disk($kind)->readStream('contract/nao-existe-' . bin2hex(random_bytes(4)));
    }
}
