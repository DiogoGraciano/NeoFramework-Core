<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Response;
use NeoFramework\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class MultipartControllerFixture extends Controller
{
    #[Route('/uploads', ['POST'], validCsrf: false)]
    public function store(ServerRequestInterface $request): Response
    {
        $file = $request->getUploadedFiles()['avatar'] ?? null;

        return (new Response())->json([
            'title' => $request->getParsedBody()['title'] ?? null,
            'filename' => $file instanceof UploadedFileInterface ? $file->getClientFilename() : null,
            'contents' => $file instanceof UploadedFileInterface ? (string) $file->getStream() : null,
        ]);
    }
}

final class NeoFrameworkMultipartTest extends TestCase
{
    public function testMultipartUploadsReachTheControllerAsPsrUploadedFiles(): void
    {
        $file = TestClient::uploadedFile('image-bytes', 'avatar.png', 'image/png');

        TestClient::forControllers([MultipartControllerFixture::class])
            ->postMultipart('/uploads', ['title' => 'Avatar'], ['avatar' => $file])
            ->assertOk()
            ->assertJson(['title' => 'Avatar', 'filename' => 'avatar.png', 'contents' => 'image-bytes']);
    }
}
