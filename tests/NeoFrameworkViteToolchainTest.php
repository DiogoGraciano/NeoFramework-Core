<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\ViteToolchain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NeoFrameworkViteToolchainTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = \sys_get_temp_dir() . '/neotool_' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
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

    private function packageJson(): void
    {
        \file_put_contents($this->dir . '/package.json', '{}');
    }

    private function config(string $name = 'vite.config.js'): void
    {
        \file_put_contents($this->dir . '/' . $name, '');
    }

    /** Binario falso executavel: run() o invoca de verdade. */
    private function binary(): void
    {
        \mkdir($this->dir . '/node_modules/.bin', 0777, true);
        \file_put_contents($this->dir . '/node_modules/.bin/vite', "#!/bin/sh\nexit 0\n");
        \chmod($this->dir . '/node_modules/.bin/vite', 0755);
    }

    private function make(?string $root = null): ViteToolchain
    {
        return new ViteToolchain($root ?? $this->dir);
    }

    public function testProjetoCompletoNaoTemProblemas()
    {
        $this->packageJson();
        $this->config();
        $this->binary();

        $this->assertSame([], $this->make()->diagnose());
    }

    public function testDiagnosticaPackageJsonAusente()
    {
        $this->config();
        $this->binary();

        $problems = $this->make()->diagnose();

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('package.json', $problems[0]);
    }

    public function testDiagnosticaConfigAusente()
    {
        $this->packageJson();
        $this->binary();

        $problems = $this->make()->diagnose();

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('vite.config.js', $problems[0]);
    }

    public function testDiagnosticaNodeModulesAusenteComOComandoAResolver()
    {
        $this->packageJson();
        $this->config();

        $problems = $this->make()->diagnose();

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('npm install', $problems[0]);
    }

    public function testProjetoVazioListaOsTresProblemas()
    {
        $this->assertCount(3, $this->make()->diagnose());
    }

    #[DataProvider('nomesDeConfig')]
    public function testAceitaAsVariantesDeNomeDoConfig(string $name)
    {
        $this->config($name);

        $this->assertSame($this->dir . '/' . $name, $this->make()->configFile());
    }

    public static function nomesDeConfig(): array
    {
        return [
            ['vite.config.js'],
            ['vite.config.mjs'],
            ['vite.config.ts'],
            ['vite.config.mts'],
        ];
    }

    public function testBinarioAusenteRetornaNull()
    {
        $this->assertNull($this->make()->binary());
    }

    public function testRootSemBarraFinalEhNormalizado()
    {
        $this->packageJson();
        $this->config();
        $this->binary();

        $this->assertSame([], $this->make(\rtrim($this->dir, '/'))->diagnose());
    }

    public function testCommandEscapaOsArgumentos()
    {
        $this->binary();

        $command = $this->make()->command(['build', '--mode', 'production']);

        $this->assertStringContainsString("'build'", $command);
        $this->assertStringContainsString("'--mode'", $command);
        $this->assertStringContainsString("'production'", $command);
    }

    public function testCommandEscapaRootComEspaco()
    {
        $root = $this->dir . '/com espaco';
        \mkdir($root . '/node_modules/.bin', 0777, true);
        \file_put_contents($root . '/node_modules/.bin/vite', '');

        $command = $this->make($root)->command(['build']);

        // Sem aspas o shell quebraria o caminho em dois argumentos.
        $this->assertStringContainsString("'" . $root . "/node_modules/.bin/vite'", $command);
    }

    public function testCommandSemArgumentosEhSoOBinario()
    {
        $this->binary();

        $this->assertSame(
            "'" . $this->dir . "/node_modules/.bin/vite'",
            $this->make()->command([])
        );
    }

    public function testRunRestauraODiretorioCorrente()
    {
        $this->binary();

        $before = \getcwd();
        $this->make()->run(['--version']);

        $this->assertSame($before, \getcwd());
    }
}
