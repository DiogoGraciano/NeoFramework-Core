<?php

namespace Tests;

use NeoFramework\Core\Enums\FileStorageType;
use NeoFramework\Core\FileStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regressão dos vetores de upload.
 *
 * A allowlist de imagens aceitava image/svg+xml — que executa JavaScript quando
 * servido do próprio domínio — e a extensão enviada pelo cliente era preservada,
 * de modo que um .php acabava dentro de public/assets.
 */
class NeoFrameworkFileStorageTest extends TestCase
{
    private FileStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new FileStorage();
    }

    public static function extensoesBloqueadas(): array
    {
        return [
            'php' => ['payload.php'],
            'phtml' => ['payload.phtml'],
            'phar' => ['payload.phar'],
            'htaccess' => ['.htaccess'],
            'svg' => ['imagem.svg'],
            'html' => ['pagina.html'],
            'dupla extensao' => ['imagem.jpg.php'],
        ];
    }

    #[DataProvider('extensoesBloqueadas')]
    public function testExtensaoExecutavelEhRecusadaMesmoComTipoAny(string $fileName)
    {
        // ANY é o tipo mais permissivo e ainda assim não pode aceitar executável.
        $this->assertFalse($this->storage->validExtension($fileName, FileStorageType::ANY));
    }

    public function testSvgNaoEhAceitoComoImagem()
    {
        $this->assertFalse($this->storage->validExtension('logo.svg', FileStorageType::IMAGE));
    }

    public function testImagemComExtensaoValidaEhAceita()
    {
        $this->assertTrue($this->storage->validExtension('foto.jpg', FileStorageType::IMAGE));
        $this->assertTrue($this->storage->validExtension('foto.PNG', FileStorageType::IMAGE));
    }

    public function testArquivoSemExtensaoEhRecusado()
    {
        $this->assertFalse($this->storage->validExtension('arquivo', FileStorageType::ANY));
    }

    public function testDocumentoNaoAceitaExtensaoDeImagem()
    {
        $this->assertFalse($this->storage->validExtension('foto.jpg', FileStorageType::DOCUMENT));
        $this->assertTrue($this->storage->validExtension('contrato.pdf', FileStorageType::DOCUMENT));
    }

    public function testMimeDeSvgEhRecusadoComoImagem()
    {
        $path = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        try {
            $this->assertFalse($this->storage->validType($path, FileStorageType::IMAGE));
        } finally {
            unlink($path);
        }
    }

    public function testArquivoInexistenteNaoDerruba()
    {
        // mime_content_type e filesize devolvem false e emitem warning; os
        // try/catch anteriores não capturavam nada.
        $inexistente = sys_get_temp_dir() . '/nao-existe-' . bin2hex(random_bytes(4));

        $this->assertFalse($this->storage->validType($inexistente, FileStorageType::IMAGE));
        $this->assertFalse($this->storage->validSize($inexistente, 1000));
    }
}
