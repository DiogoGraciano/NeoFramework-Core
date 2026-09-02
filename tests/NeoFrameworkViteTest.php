<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Config;
use NeoFramework\Core\Config\ConfigRepository;
use NeoFramework\Core\Vite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Nenhum caso aqui toca \NeoFramework\Core\Support\ProjectRoot::path(): a instancia recebe publicPath,
 * hotFile e urlBase pelo construtor. getRoot() deriva a raiz da posicao do
 * pacote dentro de vendor/ e aponta para fora do repositorio quando os testes
 * rodam standalone.
 */
class NeoFrameworkViteTest extends TestCase
{
    private string $dir;

    private string $public;

    private string $hotFile;

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
        Config::setRepository(new ConfigRepository(sys_get_temp_dir(), ['app' => ['environment' => 'dev', 'url' => ''], 'vite' => ['enabled_in_production' => false]], false));

        $this->dir = \sys_get_temp_dir() . '/neovite_' . \bin2hex(\random_bytes(6));
        $this->public = $this->dir . '/public';
        $this->hotFile = $this->dir . '/hot';

        \mkdir($this->public . '/build', 0777, true);

        Vite::reset();
    }

    protected function tearDown(): void
    {
        Vite::reset();
        $_ENV = $this->envBackup;
        Config::reset();
        self::removeTree($this->dir);
        parent::tearDown();
    }

    /**
     * Remocao recursiva sem GLOB_BRACE: a constante e uma extensao GNU e nao
     * existe em builds musl, e a imagem do projeto e php:8.4-fpm-alpine.
     */
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

    private function make(?string $urlBase = 'https://exemplo.test/', array $entrypoints = []): Vite
    {
        return new Vite(
            publicPath: $this->public,
            hotFile: $this->hotFile,
            buildPath: 'build',
            urlBase: $urlBase,
            entrypoints: $entrypoints,
        );
    }

    /** Escreve o arquivo hot com o conteudo bruto informado. */
    private function hot(string $content): void
    {
        \file_put_contents($this->hotFile, $content);
    }

    private function useProductionConfig(): void
    {
        Config::setRepository(new ConfigRepository(sys_get_temp_dir(), ['app' => ['environment' => 'prod', 'url' => ''], 'vite' => ['enabled_in_production' => false]], false));
    }

    /** Escreve o manifest. Aceita string para exercitar JSON invalido. */
    private function manifest(array|string $data): void
    {
        \file_put_contents(
            $this->public . '/build/manifest.json',
            \is_string($data) ? $data : \json_encode($data)
        );
    }

    // ====================================================== modo hot

    public function testHotEmiteOClientAntesDaEntrada()
    {
        $this->hot('http://localhost:5173');

        $html = $this->make()->render(['resources/js/app.js']);

        $this->assertStringContainsString('http://localhost:5173/@vite/client', $html);
        $this->assertStringContainsString('http://localhost:5173/resources/js/app.js', $html);
        $this->assertLessThan(
            \strpos($html, '/resources/js/app.js'),
            \strpos($html, '/@vite/client'),
            'O client precisa vir antes: e ele que instala import.meta.hot.'
        );
    }

    public function testHotEmiteLinkParaEntradaCss()
    {
        $this->hot('http://localhost:5173');

        $html = $this->make()->render(['resources/css/app.css']);

        $this->assertStringContainsString(
            '<link rel="stylesheet" href="http://localhost:5173/resources/css/app.css">',
            $html
        );
        $this->assertStringNotContainsString('src="http://localhost:5173/resources/css/app.css"', $html);
    }

    public function testHotEIgnoradoEmProducao()
    {
        $this->useProductionConfig();

        $this->hot('http://localhost:5173');
        $this->manifest(['resources/js/app.js' => ['file' => 'assets/app-abc.js', 'isEntry' => true]]);

        $html = $this->make()->render(['resources/js/app.js']);

        $this->assertStringNotContainsString('localhost:5173', $html);
        $this->assertStringContainsString('https://exemplo.test/build/assets/app-abc.js', $html);
    }

    public function testHotNormalizaEspacosEBarraFinal()
    {
        $this->hot("  http://localhost:5173/  \n");

        $this->assertSame('http://localhost:5173', $this->make()->hotUrl());
    }

    #[DataProvider('hotInvalido')]
    public function testHotInvalidoEhTratadoComoFrio(string $content)
    {
        $this->hot($content);
        $this->manifest(['resources/js/app.js' => ['file' => 'assets/app-abc.js', 'isEntry' => true]]);

        $vite = $this->make();

        $this->assertFalse($vite->isHot());
        $this->assertStringContainsString('assets/app-abc.js', $vite->render(['resources/js/app.js']));
    }

    public static function hotInvalido(): array
    {
        return [
            'vazio' => [''],
            'so espacos' => ["   \n"],
            'nao e url' => ['nao-e-url'],
            'esquema perigoso' => ['javascript:alert(1)'],
            'com caminho' => ['http://localhost:5173/algum/caminho'],
        ];
    }

    public function testHotNaoLeOManifest()
    {
        // Sem manifest no disco: se o modo hot o lesse, isto lancaria.
        $this->hot('http://localhost:5173');

        $html = $this->make()->render(['resources/js/app.js']);

        $this->assertStringContainsString('@vite/client', $html);
    }

    // ====================================================== modo build

    public function testEntradaSimplesEmiteApenasUmScript()
    {
        $this->manifest(['resources/js/app.js' => ['file' => 'assets/app-abc.js', 'isEntry' => true]]);

        $html = $this->make()->render(['resources/js/app.js']);

        $this->assertSame(
            '<script type="module" src="https://exemplo.test/build/assets/app-abc.js"></script>',
            $html
        );
    }

    public function testCssDaEntradaVemAntesDoScript()
    {
        $this->manifest([
            'resources/js/app.js' => [
                'file' => 'assets/app-abc.js',
                'isEntry' => true,
                'css' => ['assets/app-def.css'],
            ],
        ]);

        $html = $this->make()->render(['resources/js/app.js']);

        $this->assertLessThan(
            \strpos($html, '<script'),
            \strpos($html, '<link rel="stylesheet"'),
            'A folha de estilo bloqueia a renderizacao e precisa vir primeiro.'
        );
    }

    public function testImportEstaticoViraModulePreload()
    {
        $this->manifest([
            'resources/js/app.js' => [
                'file' => 'assets/app-abc.js',
                'isEntry' => true,
                'imports' => ['_vendor-xyz.js'],
            ],
            '_vendor-xyz.js' => ['file' => 'assets/vendor-xyz.js'],
        ]);

        $html = $this->make()->render(['resources/js/app.js']);

        $this->assertStringContainsString(
            '<link rel="modulepreload" href="https://exemplo.test/build/assets/vendor-xyz.js">',
            $html
        );
    }

    public function testImportsAninhadosSaoPercorridos()
    {
        $this->manifest([
            'a.js' => ['file' => 'assets/a.js', 'isEntry' => true, 'imports' => ['_b.js']],
            '_b.js' => ['file' => 'assets/b.js', 'imports' => ['_c.js']],
            '_c.js' => ['file' => 'assets/c.js'],
        ]);

        $html = $this->make()->render(['a.js']);

        $this->assertStringContainsString('assets/b.js', $html);
        $this->assertStringContainsString('assets/c.js', $html);
    }

    public function testCssDeChunkAninhadoEhEmitido()
    {
        $this->manifest([
            'a.js' => ['file' => 'assets/a.js', 'isEntry' => true, 'imports' => ['_b.js']],
            '_b.js' => ['file' => 'assets/b.js', 'imports' => ['_c.js']],
            '_c.js' => ['file' => 'assets/c.js', 'css' => ['assets/c.css']],
        ]);

        $html = $this->make()->render(['a.js']);

        $this->assertStringContainsString(
            '<link rel="stylesheet" href="https://exemplo.test/build/assets/c.css">',
            $html
        );
    }

    /**
     * Grafos de modulos ES admitem ciclos e o Rollup os preserva em 'imports'.
     * Sem marcar o no visitado pela CHAVE, a recursao nao termina.
     */
    public function testCicloEntreChunksTermina()
    {
        $this->manifest([
            'a.js' => ['file' => 'assets/a.js', 'isEntry' => true, 'imports' => ['_b.js']],
            '_b.js' => ['file' => 'assets/b.js', 'imports' => ['_a-chunk.js']],
            '_a-chunk.js' => ['file' => 'assets/a-chunk.js', 'imports' => ['_b.js']],
        ]);

        $html = $this->make()->render(['a.js']);

        $this->assertSame(1, \substr_count($html, 'assets/b.js'));
        $this->assertSame(1, \substr_count($html, 'assets/a-chunk.js'));
    }

    public function testDiamanteEmiteOChunkCompartilhadoUmaVez()
    {
        $this->manifest([
            'a.js' => ['file' => 'assets/a.js', 'isEntry' => true, 'imports' => ['_b.js', '_c.js']],
            '_b.js' => ['file' => 'assets/b.js', 'imports' => ['_d.js']],
            '_c.js' => ['file' => 'assets/c.js', 'imports' => ['_d.js']],
            '_d.js' => ['file' => 'assets/d.js', 'css' => ['assets/d.css']],
        ]);

        $html = $this->make()->render(['a.js']);

        $this->assertSame(1, \substr_count($html, 'assets/d.js'));
        $this->assertSame(1, \substr_count($html, 'assets/d.css'));
    }

    public function testDynamicImportsNaoViramPreload()
    {
        $this->manifest([
            'a.js' => [
                'file' => 'assets/a.js',
                'isEntry' => true,
                'dynamicImports' => ['lazy.js'],
            ],
            'lazy.js' => ['file' => 'assets/lazy.js', 'isDynamicEntry' => true],
        ]);

        $html = $this->make()->render(['a.js']);

        $this->assertStringNotContainsString('assets/lazy.js', $html);
    }

    public function testEntradaCssNaoGeraScript()
    {
        $this->manifest([
            'resources/css/app.css' => ['file' => 'assets/app-abc.css', 'isEntry' => true],
        ]);

        $html = $this->make()->render(['resources/css/app.css']);

        $this->assertSame(
            '<link rel="stylesheet" href="https://exemplo.test/build/assets/app-abc.css">',
            $html
        );
    }

    public function testChunkCompartilhadoPorDuasEntradasAparaceUmaVez()
    {
        $this->manifest([
            'a.js' => ['file' => 'assets/a.js', 'isEntry' => true, 'imports' => ['_shared.js']],
            'b.js' => ['file' => 'assets/b.js', 'isEntry' => true, 'imports' => ['_shared.js']],
            '_shared.js' => ['file' => 'assets/shared.js'],
        ]);

        $html = $this->make()->render(['a.js', 'b.js']);

        $this->assertSame(1, \substr_count($html, 'assets/shared.js'));
    }

    /** Uma entrada tem que ganhar <script>, mesmo aparecendo no grafo de outra. */
    public function testEntradaDentroDoGrafoDeOutraContinuaSendoScript()
    {
        $this->manifest([
            'a.js' => ['file' => 'assets/a.js', 'isEntry' => true, 'imports' => ['b.js']],
            'b.js' => ['file' => 'assets/b.js', 'isEntry' => true],
        ]);

        $html = $this->make()->render(['a.js', 'b.js']);

        $this->assertStringContainsString(
            '<script type="module" src="https://exemplo.test/build/assets/b.js"></script>',
            $html
        );
        $this->assertStringNotContainsString('modulepreload', $html);
    }

    public function testImportInexistenteNoManifestEhIgnorado()
    {
        $this->manifest([
            'a.js' => ['file' => 'assets/a.js', 'isEntry' => true, 'imports' => ['_removido.js']],
        ]);

        $html = $this->make()->render(['a.js']);

        $this->assertStringContainsString('assets/a.js', $html);
    }

    // ====================================================== erros

    public function testManifestAusenteLancaComOCaminho()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($this->public . '/build/manifest.json');

        $this->make()->render(['resources/js/app.js']);
    }

    public function testManifestInvalidoLanca()
    {
        $this->manifest('{ isso nao e json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inválido');

        $this->make()->render(['resources/js/app.js']);
    }

    public function testEntradaForaDoManifestLancaNomeandoAEntrada()
    {
        $this->manifest(['outra.js' => ['file' => 'assets/outra.js', 'isEntry' => true]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('resources/js/app.js');

        $this->make()->render(['resources/js/app.js']);
    }

    // ====================================================== urls e escape

    public function testUrlBaseComSubdiretorio()
    {
        $this->manifest(['a.js' => ['file' => 'assets/a.js', 'isEntry' => true]]);

        $html = $this->make('https://exemplo.test/app/')->render(['a.js']);

        $this->assertStringContainsString('https://exemplo.test/app/build/assets/a.js', $html);
    }

    public function testAspasNoNomeDoArquivoSaoEscapadas()
    {
        $this->manifest(['a.js' => ['file' => 'assets/a".js', 'isEntry' => true]]);

        $html = $this->make()->render(['a.js']);

        $this->assertStringNotContainsString('a".js', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    /** Pega vazamento dos conjuntos de dedupe entre chamadas. */
    public function testRenderEhIdempotente()
    {
        $this->manifest([
            'a.js' => ['file' => 'assets/a.js', 'isEntry' => true, 'imports' => ['_b.js']],
            '_b.js' => ['file' => 'assets/b.js', 'css' => ['assets/b.css']],
        ]);

        $vite = $this->make();

        $this->assertSame($vite->render(['a.js']), $vite->render(['a.js']));
    }

    public function testRenderSemArgumentosUsaAsEntradasDaInstancia()
    {
        $this->manifest(['padrao.js' => ['file' => 'assets/padrao.js', 'isEntry' => true]]);

        $html = $this->make('https://exemplo.test/', ['padrao.js'])->render();

        $this->assertStringContainsString('assets/padrao.js', $html);
    }

    public function testSemEntradasRetornaVazio()
    {
        $this->assertSame('', $this->make()->render());
    }

    // ====================================================== assets

    public function testAssetUrlNoModoBuild()
    {
        $this->manifest(['resources/img/logo.svg' => ['file' => 'assets/logo-abc.svg']]);

        $this->assertSame(
            'https://exemplo.test/build/assets/logo-abc.svg',
            $this->make()->assetUrl('resources/img/logo.svg')
        );
    }

    public function testAssetUrlNoModoHot()
    {
        $this->hot('http://localhost:5173');

        $this->assertSame(
            'http://localhost:5173/resources/img/logo.svg',
            $this->make()->assetUrl('resources/img/logo.svg')
        );
    }

    public function testAssetAusenteLanca()
    {
        $this->manifest([]);

        $this->expectException(RuntimeException::class);

        $this->make()->assetUrl('resources/img/logo.svg');
    }

    // ====================================================== csp

    public function testCspSourcesVazioQuandoFrio()
    {
        $this->assertSame([], $this->make()->cspSources());
    }

    public function testCspSourcesQuandoQuente()
    {
        $this->hot('http://localhost:5173');

        $sources = $this->make()->cspSources();

        $this->assertContains('http://localhost:5173', $sources['script-src']);
        $this->assertContains('ws://localhost:5173', $sources['connect-src']);
        $this->assertContains("'unsafe-inline'", $sources['style-src']);
        $this->assertArrayHasKey('img-src', $sources);
        $this->assertArrayHasKey('font-src', $sources);
    }

    public function testCspUsaWssQuandoODevServerEhHttps()
    {
        $this->hot('https://localhost:5173');

        $this->assertContains('wss://localhost:5173', $this->make()->cspSources()['connect-src']);
    }

    public function testCspSourcesVazioEmProducaoMesmoComHot()
    {
        $this->useProductionConfig();
        $this->hot('http://localhost:5173');

        $this->assertSame([], $this->make()->cspSources());
    }
}
