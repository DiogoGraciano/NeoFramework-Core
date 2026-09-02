<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Abstract\Layout;
use NeoFramework\Core\Vite;
use PHPUnit\Framework\TestCase;

/**
 * O Layout resolve o caminho dos templates por \NeoFramework\Core\Support\ProjectRoot::path(), que aponta
 * para fora do repositorio quando os testes rodam standalone. Por isso cada caso
 * usa uma subclasse que sobrescreve templatePath() para o diretorio temporario.
 */
class NeoFrameworkLayoutTest extends TestCase
{
    private string $dir;

    /** @var array<string,mixed> */
    private array $envBackup;

    protected function setUp(): void
    {
        parent::setUp();

        // Guarda e restaura o ambiente em vez de zerá-lo: `$_ENV = []` apagava
        // também as variáveis que o phpunit.xml injeta (JOBS_STORAGE_PATH,
        // QUEUE_DRIVER...), quebrando outros testes em ordem aleatória.
        $this->envBackup = $_ENV;
        $_ENV = [];

        $this->dir = \sys_get_temp_dir() . '/neolay_' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir . '/public/build', 0777, true);

        Vite::setInstance($this->vite());
    }

    protected function tearDown(): void
    {
        Vite::reset();
        $_ENV = $this->envBackup;
        self::removeTree($this->dir);
        parent::tearDown();
    }

    /** Sem GLOB_BRACE: a constante nao existe em builds musl (alpine). */
    private static function removeTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (\is_dir($path)) {
                self::removeTree($path);
            } else {
                @\unlink($path);
            }
        }

        @\rmdir($dir);
    }

    private function vite(): Vite
    {
        \file_put_contents($this->dir . '/public/build/manifest.json', \json_encode([
            'resources/js/app.js' => ['file' => 'assets/app-padrao.js', 'isEntry' => true],
            'resources/js/admin.js' => ['file' => 'assets/admin-area.js', 'isEntry' => true],
        ]));

        return new Vite(
            publicPath: $this->dir . '/public',
            hotFile: $this->dir . '/hot',
            buildPath: 'build',
            urlBase: 'https://exemplo.test/',
            entrypoints: ['resources/js/app.js'],
        );
    }

    private function tpl(string $name, string $content): void
    {
        \file_put_contents($this->dir . '/' . $name, $content);
    }

    /** Layout de teste: templates saem do diretorio temporario. */
    private function layout(array $entrypoints = []): Layout
    {
        return new class ($this->dir, $entrypoints) extends Layout {
            public function __construct(private string $base, array $entrypoints)
            {
                $this->viteEntrypoints = $entrypoints;
            }

            protected function templatePath(string $caminho): string
            {
                return $this->base . '/' . $caminho;
            }

            public function open(string $name): string
            {
                return $this->getTemplate($name)->parse();
            }

            public function openViaSetTemplate(string $name): string
            {
                $this->setTemplate($name);

                return $this->parse();
            }
        };
    }

    public function testPlaceholderRecebeAsTagsDoVite()
    {
        $this->tpl('com.html', '<head>{neof_vite}</head>');

        $html = $this->layout()->open('com.html');

        $this->assertStringContainsString(
            '<script type="module" src="https://exemplo.test/build/assets/app-padrao.js"></script>',
            $html
        );
    }

    /** A guarda exists() e o que torna isso gratuito para quem nao usa. */
    public function testTemplateSemOPlaceholderNaoLanca()
    {
        $this->tpl('sem.html', '<head><title>oi</title></head>');

        $this->assertStringContainsString('oi', $this->layout()->open('sem.html'));
    }

    public function testEntrypointsDaSubclasseTemPrecedencia()
    {
        $this->tpl('com.html', '<head>{neof_vite}</head>');

        $html = $this->layout(['resources/js/admin.js'])->open('com.html');

        $this->assertStringContainsString('assets/admin-area.js', $html);
        $this->assertStringNotContainsString('assets/app-padrao.js', $html);
    }

    public function testSemEntrypointsUsaAListaDaInstancia()
    {
        $this->tpl('com.html', '<head>{neof_vite}</head>');

        $this->assertStringContainsString('assets/app-padrao.js', $this->layout()->open('com.html'));
    }

    public function testSetTemplateInjetaIgualAGetTemplate()
    {
        $this->tpl('com.html', '<head>{neof_vite}</head>');

        $this->assertSame(
            $this->layout()->open('com.html'),
            $this->layout()->openViaSetTemplate('com.html')
        );
    }
}
