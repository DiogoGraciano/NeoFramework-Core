<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * \NeoFramework\Core\Support\ProjectRoot::path() era um dirname() de profundidade fixa, que só acerta
 * quando o framework está exatamente em <projeto>/vendor/diogodg/neoframework.
 * Com path repository e symlink o __DIR__ resolve para o repositório real da
 * lib e a contagem cai fora do projeto — sem erro, só com o caminho errado.
 */
class NeoFrameworkFunctionsRootTest extends TestCase
{
    private string $dir;

    /** @var array<string,string|null> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = \sys_get_temp_dir() . '/neoroot_' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir, 0777, true);

        foreach (['NEOFRAMEWORK_ROOT'] as $key) {
            $this->previousEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key], $_SERVER[$key]);
        }

        \NeoFramework\Core\Support\ProjectRoot::set(null);
    }

    protected function tearDown(): void
    {
        \NeoFramework\Core\Support\ProjectRoot::set(null);

        foreach ($this->previousEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        self::removeTree($this->dir);

        parent::tearDown();
    }

    public function testAlwaysEndsWithADirectorySeparator(): void
    {
        $this->assertStringEndsWith(DIRECTORY_SEPARATOR, \NeoFramework\Core\Support\ProjectRoot::path());
    }

    public function testResolvesToADirectoryThatExists(): void
    {
        $this->assertDirectoryExists(\NeoFramework\Core\Support\ProjectRoot::path());
    }

    public function testDoesNotResolveOutsideTheRepositoryWhenRunningStandalone(): void
    {
        // O comportamento antigo (dirname x4) devolvia o diretório que CONTÉM o
        // repositório. A raiz precisa ser o repositório ou algo dentro dele.
        $repository = \dirname(__DIR__);

        $this->assertStringStartsWith(
            \rtrim($repository, '/\\'),
            \rtrim(\NeoFramework\Core\Support\ProjectRoot::path(), '/\\'),
        );
    }

    public function testEnvironmentOverrideWins(): void
    {
        $_ENV['NEOFRAMEWORK_ROOT'] = $this->dir;

        $this->assertSame($this->dir . DIRECTORY_SEPARATOR, \NeoFramework\Core\Support\ProjectRoot::path());
    }

    public function testEnvironmentOverrideIsNormalizedToASingleTrailingSeparator(): void
    {
        $_ENV['NEOFRAMEWORK_ROOT'] = $this->dir . '///';

        $this->assertSame($this->dir . DIRECTORY_SEPARATOR, \NeoFramework\Core\Support\ProjectRoot::path());
    }

    public function testEmptyEnvironmentOverrideIsIgnored(): void
    {
        $_ENV['NEOFRAMEWORK_ROOT'] = '';

        $this->assertDirectoryExists(\NeoFramework\Core\Support\ProjectRoot::path());
    }

    public function testResultIsMemoized(): void
    {
        $first = \NeoFramework\Core\Support\ProjectRoot::path();

        $_ENV['NEOFRAMEWORK_ROOT'] = $this->dir;

        $this->assertSame($first, \NeoFramework\Core\Support\ProjectRoot::path(), 'getRoot() deve resolver uma vez por processo');
    }

    public function testSetRootOverridesAndNullRestoresDiscovery(): void
    {
        \NeoFramework\Core\Support\ProjectRoot::set($this->dir);
        $this->assertSame($this->dir . DIRECTORY_SEPARATOR, \NeoFramework\Core\Support\ProjectRoot::path());

        \NeoFramework\Core\Support\ProjectRoot::set(null);
        $this->assertNotSame($this->dir . DIRECTORY_SEPARATOR, \NeoFramework\Core\Support\ProjectRoot::path());
    }

    /**
     * O caso que motiva a mudança: um projeto de verdade (composer.json + App/)
     * acima do diretório de trabalho deve vencer, independentemente de onde o
     * código da biblioteca esteja instalado.
     */
    public function testFindsTheProjectRootWalkingUpFromTheWorkingDirectory(): void
    {
        $project = $this->dir . '/projeto';
        \mkdir($project . '/App/Controllers', 0777, true);
        \mkdir($project . '/public', 0777, true);
        \file_put_contents($project . '/composer.json', '{}');

        $previousCwd = \getcwd();
        \chdir($project . '/public');

        try {
            \NeoFramework\Core\Support\ProjectRoot::set(null);

            $this->assertSame(
                \realpath($project) . DIRECTORY_SEPARATOR,
                \NeoFramework\Core\Support\ProjectRoot::path(),
            );
        } finally {
            if ($previousCwd !== false) {
                \chdir($previousCwd);
            }
        }
    }

    /**
     * Um composer.json sem App/ não é um projeto NeoFramework — é uma
     * dependência ou um diretório qualquer, e não pode sequestrar a raiz.
     */
    public function testComposerJsonWithoutAnAppDirectoryIsNotTreatedAsTheProjectRoot(): void
    {
        $notAProject = $this->dir . '/lib';
        \mkdir($notAProject . '/src', 0777, true);
        \file_put_contents($notAProject . '/composer.json', '{}');

        $previousCwd = \getcwd();
        \chdir($notAProject . '/src');

        try {
            \NeoFramework\Core\Support\ProjectRoot::set(null);

            $this->assertNotSame(
                \realpath($notAProject) . DIRECTORY_SEPARATOR,
                \NeoFramework\Core\Support\ProjectRoot::path(),
                'sem App/, o diretório não deve ser eleito raiz pela busca a partir do cwd',
            );
        } finally {
            if ($previousCwd !== false) {
                \chdir($previousCwd);
            }
        }
    }

    private static function removeTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            \is_dir($path) ? self::removeTree($path) : \unlink($path);
        }

        \rmdir($dir);
    }
}
