<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Enums\FileStorageType;
use NeoFramework\Core\Storage\UploadValidator;
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
    private UploadValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new UploadValidator();
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
        $this->assertNotNull($this->validator->extensionError($fileName, FileStorageType::ANY));
    }

    public function testSvgNaoEhAceitoComoImagem()
    {
        $this->assertNotNull($this->validator->extensionError('logo.svg', FileStorageType::IMAGE));
    }

    public function testImagemComExtensaoValidaEhAceita()
    {
        $this->assertNull($this->validator->extensionError('foto.jpg', FileStorageType::IMAGE));
        $this->assertNull($this->validator->extensionError('foto.PNG', FileStorageType::IMAGE));
    }

    public function testArquivoSemExtensaoEhRecusado()
    {
        $this->assertNotNull($this->validator->extensionError('arquivo', FileStorageType::ANY));
    }

    public function testDocumentoNaoAceitaExtensaoDeImagem()
    {
        $this->assertNotNull($this->validator->extensionError('foto.jpg', FileStorageType::DOCUMENT));
        $this->assertNull($this->validator->extensionError('contrato.pdf', FileStorageType::DOCUMENT));
    }

    public function testMimeDeSvgEhRecusadoComoImagem()
    {
        $path = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        try {
            $this->assertNotNull($this->validator->mimeError($path, FileStorageType::IMAGE));
        } finally {
            unlink($path);
        }
    }

    public function testArquivoInexistenteNaoDerruba()
    {
        // mime_content_type e filesize devolvem false e emitem warning; os
        // try/catch anteriores não capturavam nada.
        $inexistente = sys_get_temp_dir() . '/nao-existe-' . bin2hex(random_bytes(4));

        $this->assertNotNull($this->validator->mimeError($inexistente, FileStorageType::IMAGE));
        $this->assertNotNull($this->validator->sizeError($inexistente, 1000));
    }
}
