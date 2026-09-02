<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use GuzzleHttp\Psr7\ServerRequest;
use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Enums\FileStorageType;
use NeoFramework\Core\Events\RequestReceived;
use NeoFramework\Core\Http\Cookie;
use NeoFramework\Core\Http\ResponseNormalizer;
use NeoFramework\Core\RateLimit\InMemoryRateLimitStore;
use NeoFramework\Core\RateLimit\LazyRateLimitStore;
use NeoFramework\Core\Response;
use NeoFramework\Core\Storage\FilePersister;
use NeoFramework\Core\Storage\InMemoryDisk;
use NeoFramework\Core\Storage\MimeTypes;
use NeoFramework\Core\Storage\StoredFile;
use NeoFramework\Core\Storage\UploadRules;
use PHPUnit\Framework\TestCase;

final class NeoFrameworkCoverageContractsTest extends TestCase
{
    public function testResponseNormalizerSupportsEveryDocumentedReturnType(): void
    {
        $controller = new class extends Controller {};
        $response = new Response(201);

        self::assertSame($response, ResponseNormalizer::normalize($response, $controller));
        self::assertSame($controller->getResponse(), ResponseNormalizer::normalize(null, $controller));
        self::assertSame('texto', ResponseNormalizer::normalize('texto', $controller)->getContent());
        self::assertSame('{"ok":true}', ResponseNormalizer::normalize(['ok' => true], $controller)->getContent());

        $this->expectException(\LogicException::class);
        ResponseNormalizer::normalize(42, $controller);
    }

    public function testLazyRateLimitStoreBuildsItsDelegateOnceAndForwardsEveryOperation(): void
    {
        InMemoryRateLimitStore::reset();
        $created = 0;
        $store = new LazyRateLimitStore(static function () use (&$created): InMemoryRateLimitStore {
            ++$created;

            return new InMemoryRateLimitStore();
        });

        self::assertSame(1, $store->increment('login', 123));
        self::assertSame(1, $store->read('login'));
        $store->forget('login');
        self::assertSame(0, $store->read('login'));
        self::assertSame(1, $created, 'O factory não pode reconectar o backend a cada operação.');
    }

    public function testRequestReceivedExposesOnlySafeRequestMetadata(): void
    {
        $event = new RequestReceived(new ServerRequest('PATCH', '/orders/7'), 'request-7');

        self::assertSame('PATCH', $event->method());
        self::assertSame('/orders/7', $event->path());
        self::assertSame('request-7', $event->requestId);
    }

    public function testStorageValueObjectsExposeOnlyCanonicalMimeAndPathData(): void
    {
        self::assertSame(['image/jpeg', 'image/png', 'image/gif', 'image/avif', 'image/webp', 'image/bmp'], MimeTypes::forStorageType(FileStorageType::IMAGE));
        self::assertNotEmpty(MimeTypes::forStorageType(FileStorageType::DOCUMENT));
        self::assertNull(MimeTypes::forStorageType(FileStorageType::ANY));
        self::assertSame('pdf', MimeTypes::extensionFor('APPLICATION/PDF'));
        self::assertNull(MimeTypes::extensionFor('application/x-unknown'));

        $file = new StoredFile('public/cover.pdf', 'uploads/cover.pdf', 42, 'application/pdf');
        self::assertSame('public/cover.pdf', (string) $file);
        self::assertSame(['application/pdf'], (new UploadRules(42, ['application/pdf']))->allowedMimeTypes());

        $this->expectException(\InvalidArgumentException::class);
        new UploadRules(0);
    }

    public function testFilePersisterMapsEveryWriteModeToAPublicPath(): void
    {
        $disk = new InMemoryDisk();
        $persister = new FilePersister($disk, 'public/assets');

        self::assertSame('/public/assets/report.txt', $persister->write('report.txt', 'primeiro'));
        $persister->writeRaw('raw.txt', 'bruto');
        self::assertTrue($disk->exists('raw.txt'));

        $stream = fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, 'stream');
        rewind($stream);
        self::assertSame('/public/assets/stream.txt', $persister->writeStream('stream.txt', $stream));

        $persister->deleteIfExists('report.txt');
        $persister->deleteIfExists('missing.txt');
        $persister->delete('raw.txt');
        self::assertFalse($disk->exists('report.txt'));
        self::assertFalse($disk->exists('raw.txt'));
    }

    public function testCookieSerializesSecurityAttributesAndNormalizesExpirationInputs(): void
    {
        $cookie = new Cookie('session', 'a b', 1, '/app', 'example.test', true, true, 'None');

        self::assertStringContainsString('session=a%20b', $cookie->toHeader());
        self::assertStringContainsString('Domain=example.test', $cookie->toHeader());
        self::assertStringContainsString('Secure', $cookie->toHeader());
        self::assertSame(123, $cookie->withExpiration(new DateTimeImmutable('@123'))->expires);
        self::assertSame(456, $cookie->withExpiration(456)->expires);
        self::assertNull($cookie->withExpiration(null)->expires);
        self::assertSame(1, Cookie::expiring('session')->expires);
    }
}
