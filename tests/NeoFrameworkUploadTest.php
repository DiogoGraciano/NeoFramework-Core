<?php
declare(strict_types=1);

namespace Tests;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils;
use NeoFramework\Core\Enums\FileStorageType;
use NeoFramework\Core\Storage\DiskInterface;
use NeoFramework\Core\Storage\FilePersister;
use NeoFramework\Core\Storage\InMemoryDisk;
use NeoFramework\Core\Storage\UploadedFiles;
use NeoFramework\Core\Storage\UploadRejectedException;
use NeoFramework\Core\Storage\UploadRules;
use NeoFramework\Core\Storage\UploadScannerInterface;
use NeoFramework\Core\Storage\UploadService;
use NeoFramework\Core\Storage\UploadValidator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/** Conta quantos bytes foram realmente lidos da origem. */
final class CountingStream implements StreamInterface
{
    public int $bytesRead = 0;

    public function __construct(private readonly StreamInterface $inner) {}

    public function read(int $length): string
    {
        $chunk = $this->inner->read($length);
        $this->bytesRead += strlen($chunk);

        return $chunk;
    }

    public function __toString(): string { return $this->inner->__toString(); }
    public function close(): void { $this->inner->close(); }
    public function detach() { return $this->inner->detach(); }
    public function getSize(): ?int { return $this->inner->getSize(); }
    public function tell(): int { return $this->inner->tell(); }
    public function eof(): bool { return $this->inner->eof(); }
    public function isSeekable(): bool { return $this->inner->isSeekable(); }
    public function seek(int $offset, int $whence = SEEK_SET): void { $this->inner->seek($offset, $whence); }
    public function rewind(): void { $this->inner->rewind(); }
    public function isWritable(): bool { return $this->inner->isWritable(); }
    public function write(string $string): int { return $this->inner->write($string); }
    public function isReadable(): bool { return $this->inner->isReadable(); }
    public function getContents(): string { return $this->inner->getContents(); }
    public function getMetadata(?string $key = null) { return $this->inner->getMetadata($key); }
}

/** Falha no meio da gravação, como um disco cheio ou uma conexão que cai. */
final class FailingDisk implements DiskInterface
{
    public function __construct(private readonly InMemoryDisk $inner = new InMemoryDisk()) {}

    public function write(string $path, string $contents): void { $this->inner->write($path, $contents); }

    public function writeStream(string $path, $stream): void
    {
        // Grava metade e desiste: o resto do sistema precisa lidar com o parcial.
        $this->inner->write($path, 'parcial');
        throw new RuntimeException('disco cheio');
    }

    public function readStream(string $path) { return $this->inner->readStream($path); }
    public function exists(string $path): bool { return $this->inner->exists($path); }
    public function delete(string $path): void { $this->inner->delete($path); }
    public function paths(): array { return $this->inner->paths(); }
}

final class NeoFrameworkUploadTest extends TestCase
{
    private InMemoryDisk $disk;
    private UploadService $uploads;

    protected function setUp(): void
    {
        $this->disk = new InMemoryDisk();
        $this->uploads = new UploadService(new UploadValidator(), new FilePersister($this->disk, 'public/assets'));
    }

    /** PNG 1x1 literal: a fixture não depende da GD estar compilada. */
    private static function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    }

    private static function upload(string $contents, string $clientName, int $error = UPLOAD_ERR_OK): UploadedFile
    {
        return new UploadedFile(Utils::streamFor($contents), strlen($contents), $error, $clientName, 'image/png');
    }

    public function testStoresAnUploadUnderAServerGeneratedName(): void
    {
        $stored = $this->uploads->storeUploadedFile(self::upload(self::png(), 'Foto Do Perfil.png'), 'avatars', UploadRules::images());

        self::assertSame('image/png', $stored->mimeType);
        self::assertStringStartsWith('avatars/', $stored->storagePath);
        self::assertMatchesRegularExpression('~^avatars/[0-9a-f]+_foto-do-perfil\.png$~', $stored->storagePath);
        self::assertSame([$stored->storagePath], $this->disk->paths());
        self::assertSame('/public/assets/' . $stored->storagePath, $stored->path);
    }

    /** O nome do cliente não decide o que o arquivo é nem como ele será servido. */
    public function testTheClientNameDoesNotDecideTheStoredExtension(): void
    {
        $stored = $this->uploads->storeUploadedFile(self::upload(self::png(), 'exploit.php'), 'avatars', UploadRules::images());

        self::assertStringEndsWith('.php', 'exploit.php');
        self::assertStringEndsWith('.png', $stored->storagePath);
    }

    public function testPhpContentRenamedToAnImageIsRefused(): void
    {
        $this->expectException(UploadRejectedException::class);
        $this->uploads->storeUploadedFile(self::upload('<?php system($_GET["c"]); ?>', 'inocente.png'), 'avatars', UploadRules::images());
    }

    public function testAMimeThatTheCoreCannotNameIsRefusedEvenWithTypeAny(): void
    {
        $this->expectException(UploadRejectedException::class);
        $this->uploads->storeUploadedFile(
            self::upload("\x7fELF\x02\x01\x01" . str_repeat("\0", 64), 'binario'),
            'arquivos',
            new UploadRules(maxBytes: 1_000_000, type: FileStorageType::ANY),
        );
    }

    /** O limite é aplicado enquanto se lê, não depois. */
    public function testAnOversizedUploadIsRefusedWithoutBeingFullyRead(): void
    {
        $stream = new CountingStream(Utils::streamFor(str_repeat('a', 5_000_000)));
        $file = new UploadedFile($stream, 5_000_000, UPLOAD_ERR_OK, 'grande.png', 'image/png');

        try {
            $this->uploads->storeUploadedFile($file, 'avatars', new UploadRules(maxBytes: 100_000, type: FileStorageType::ANY));
            self::fail('O upload deveria ter sido recusado.');
        } catch (UploadRejectedException $expected) {
            self::assertSame('File size is not allowed', $expected->getMessage());
        }

        // Parou logo depois de passar do limite, em vez de ler os 5 MB.
        self::assertLessThan(150_000, $stream->bytesRead);
        self::assertSame([], $this->disk->paths());
    }

    public function testAFailedUploadNeverReachesTheDisk(): void
    {
        try {
            $this->uploads->storeUploadedFile(self::upload('', 'vazio.png', UPLOAD_ERR_NO_FILE), 'avatars', UploadRules::images());
            self::fail('O upload deveria ter sido recusado.');
        } catch (UploadRejectedException $expected) {
            self::assertSame('File not found in the request', $expected->getMessage());
        }

        self::assertSame([], $this->disk->paths());
    }

    /** Gravação que falha no meio não deixa arquivo pela metade servível. */
    public function testAPartialWriteIsCleanedUp(): void
    {
        $disk = new FailingDisk();
        $uploads = new UploadService(new UploadValidator(), new FilePersister($disk, 'public/assets'));

        try {
            $uploads->storeUploadedFile(self::upload(self::png(), 'foto.png'), 'avatars', UploadRules::images());
            self::fail('A gravação deveria ter falhado.');
        } catch (RuntimeException $expected) {
            self::assertSame('disco cheio', $expected->getMessage());
        }

        self::assertSame([], $disk->paths());
    }

    public function testADirectoryCannotEscapeTheStorageRoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->uploads->storeUploadedFile(self::upload(self::png(), 'foto.png'), '../../etc', UploadRules::images());
    }

    public function testTheTemporaryFileIsAlwaysRemoved(): void
    {
        $before = glob(sys_get_temp_dir() . '/neof_upload_*') ?: [];

        $this->uploads->storeUploadedFile(self::upload(self::png(), 'foto.png'), 'avatars', UploadRules::images());
        try {
            $this->uploads->storeUploadedFile(self::upload('nao-e-imagem', 'foto.png'), 'avatars', UploadRules::images());
        } catch (UploadRejectedException) {
        }

        self::assertSame($before, glob(sys_get_temp_dir() . '/neof_upload_*') ?: []);
    }

    public function testNestedMultipartTreesAreReachableByPath(): void
    {
        $request = (new ServerRequest('POST', '/x'))->withUploadedFiles([
            'avatar' => self::upload(self::png(), 'a.png'),
            'documentos' => [['arquivo' => self::upload(self::png(), 'b.png')]],
        ]);

        self::assertNotNull(UploadedFiles::get($request, 'avatar'));
        self::assertNotNull(UploadedFiles::get($request, 'documentos.0.arquivo'));
        self::assertNull(UploadedFiles::get($request, 'documentos.1.arquivo'));
        self::assertSame(['avatar', 'documentos.0.arquivo'], array_keys(UploadedFiles::flatten($request)));
    }

    /** O scanner roda sobre o temporário; recusar ali significa não publicar nada. */
    public function testTheScannerCanRefuseBeforeAnythingReachesTheDisk(): void
    {
        $scanner = new class implements UploadScannerInterface {
            public ?string $seenMimeType = null;

            public function scan(string $temporaryPath, string $mimeType): void
            {
                $this->seenMimeType = $mimeType;
                if (str_contains((string) file_get_contents($temporaryPath), 'EICAR')) throw new UploadRejectedException('File type is not allowed');
            }
        };
        $uploads = new UploadService(new UploadValidator(), new FilePersister($this->disk, 'public/assets'), $scanner);

        $uploads->storeUploadedFile(self::upload(self::png(), 'limpo.png'), 'avatars', UploadRules::images());
        self::assertSame('image/png', $scanner->seenMimeType);
        self::assertCount(1, $this->disk->paths());

        $infected = self::png() . 'EICAR';
        $this->expectException(UploadRejectedException::class);
        try {
            $uploads->storeUploadedFile(self::upload($infected, 'virus.png'), 'avatars', UploadRules::images());
        } finally {
            self::assertCount(1, $this->disk->paths());
        }
    }

    public function testAnExplicitMimeAllowlistOverridesTheType(): void
    {
        $this->expectException(UploadRejectedException::class);
        $this->uploads->storeUploadedFile(
            self::upload(self::png(), 'foto.png'),
            'avatars',
            new UploadRules(maxBytes: 1_000_000, mimeTypes: ['image/jpeg']),
        );
    }
}
