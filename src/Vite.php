<?php
declare(strict_types=1);

namespace NeoFramework\Core;

use NeoFramework\Core\Config\AppConfig;
use NeoFramework\Core\Config\ViteConfig;
use NeoFramework\Core\Vite\ManifestGraph;
use NeoFramework\Core\Vite\ManifestReader;
use NeoFramework\Core\Vite\TagRenderer;
use NeoFramework\Core\Vite\ViteFactory;
use RuntimeException;

/**
 * Integração com o Vite.
 *
 * Dois modos, decididos por isHot():
 *
 *  - HOT   — o dev server está no ar. As tags apontam para ele e o manifest
 *            nunca é lido (em desenvolvimento ele nem costuma existir).
 *  - BUILD — as tags saem de public/build/manifest.json.
 *
 * Nenhum método de instância chama \NeoFramework\Core\Support\ProjectRoot::path(): todos os caminhos
 * chegam pelo construtor. instance() é o único ponto que resolve a raiz, e
 * setInstance() permite trocá-la em teste — getRoot() deriva a raiz da posição
 * do pacote dentro de vendor/ e não vale nada quando o repositório é usado
 * fora de uma aplicação instalada.
 *
 * @author Diogo Graciano
 */
final class Vite
{
    private static ?self $instance = null;

    private ?string $hotUrl = null;

    private bool $hotChecked = false;

    private ManifestReader $manifestReader;

    private TagRenderer $tagRenderer;

    private ManifestGraph $manifestGraph;

    /**
     * @param string       $publicPath  diretório público, absoluto
     * @param string       $hotFile     caminho absoluto do arquivo `hot`
     * @param string       $buildPath   subdiretório de build, relativo a $publicPath E à URL base
     * @param string|null  $urlBase     prefixo público terminado em barra; null resolve por Url::getUrlBase()
     * @param list<string> $entrypoints entradas usadas quando render() não recebe nenhuma
     */
    public function __construct(
        private string $publicPath,
        private string $hotFile,
        private string $buildPath = 'build',
        private ?string $urlBase = null,
        private array $entrypoints = [],
    ) {
        $this->publicPath = rtrim($publicPath, '/');
        $this->buildPath = trim($buildPath, '/');
        $this->manifestReader = new ManifestReader($this->publicPath . '/' . $this->buildPath . '/manifest.json');
        $this->tagRenderer = new TagRenderer();
        $this->manifestGraph = new ManifestGraph();
    }

    // --------------------------------------------------------------- fachada

    /**
     * Instância padrão, derivada da raiz da aplicação e de Config/vite.config.php.
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = ViteFactory::fromProjectRoot();
        }

        return self::$instance;
    }

    /** Troca a instância padrão. Existe para teste. */
    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    /** Descarta a instância padrão, forçando releitura da configuração. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Tags de uma ou mais entradas. Sem argumentos usa Config/vite.config.php.
     */
    public static function tags(string ...$entrypoints): string
    {
        return self::instance()->render($entrypoints);
    }

    /** URL pública de um asset declarado no manifest. */
    public static function asset(string $path): string
    {
        return self::instance()->assetUrl($path);
    }

    public static function isRunningHot(): bool
    {
        return self::instance()->isHot();
    }

    // -------------------------------------------------------------- modo dev

    public function isHot(): bool
    {
        return $this->hotUrl() !== null;
    }

    /**
     * Origem do dev server, ou null quando ele não está no ar.
     *
     * Exige DUAS condições: ENVIRONMENT != "prod" E um arquivo `hot` contendo
     * uma origem sintaticamente válida. Nenhuma sozinha basta — é o que garante
     * que um `hot` deixado para trás por um dev server morto abruptamente jamais
     * altere o comportamento de um servidor de produção, e que lixo no arquivo
     * não vire uma URL arbitrária dentro de um <script src>.
     */
    public function hotUrl(): ?string
    {
        if ($this->hotChecked) {
            return $this->hotUrl;
        }

        $this->hotChecked = true;

        $app = AppConfig::from(Config::repository());
        $config = ViteConfig::from(Config::repository());
        if (($app->isProduction() && !$config->enabledInProduction) || !is_file($this->hotFile)) {
            return $this->hotUrl = null;
        }

        $url = rtrim(trim((string) file_get_contents($this->hotFile)), '/');

        if (!preg_match('#^https?://[A-Za-z0-9\-._]+(:\d{1,5})?$#', $url)) {
            return $this->hotUrl = null;
        }

        return $this->hotUrl = $url;
    }

    // ---------------------------------------------------------------- render

    /**
     * @param list<string> $entrypoints
     */
    public function render(array $entrypoints = []): string
    {
        $entrypoints = $entrypoints ?: $this->entrypoints;

        if ($entrypoints === []) {
            return '';
        }

        return $this->isHot()
            ? $this->renderHot($entrypoints)
            : $this->renderManifest($entrypoints);
    }

    /**
     * @param list<string> $entrypoints
     */
    private function renderHot(array $entrypoints): string
    {
        $base = (string) $this->hotUrl();

        // O client vem antes de qualquer módulo da aplicação: é ele que instala
        // import.meta.hot e abre o WebSocket.
        return $this->tagRenderer->hot($base, $entrypoints);
    }

    /**
     * Modo build: travessia do grafo de chunks do manifest.
     *
     * Três baldes, emitidos nesta ordem: folhas de estilo (bloqueiam a
     * renderização, então têm prioridade), modulepreload (dicas de fetch) e por
     * último os <script type="module"> das entradas.
     *
     * @param list<string> $entrypoints
     */
    private function renderManifest(array $entrypoints): string
    {
        $assets = $this->manifestGraph->resolve($this->manifest(), $entrypoints, $this->manifestFile());

        return $this->tagRenderer->build(
            array_map($this->url(...), $assets['stylesheets']),
            array_map($this->url(...), $assets['preloads']),
            array_map($this->url(...), $assets['scripts']),
        );
    }

    // -------------------------------------------------------------- manifest

    public function manifestFile(): string
    {
        return $this->manifestReader->file();
    }

    /**
     * Falha alto, e não em silêncio.
     *
     * Sem manifest a página sai sem CSS e sem JS. Um retorno vazio produziria um
     * site quebrado e mudo, cuja causa não aparece em lugar nenhum; a exceção cai
     * no handler do Kernel, que em produção registra no log e devolve 500.
     *
     * @return array<string,array>
     */
    private function manifest(): array
    {
        return $this->manifestReader->all();
    }

    public function assetUrl(string $path): string
    {
        if ($this->isHot()) {
            return $this->hotUrl() . '/' . ltrim($path, '/');
        }

        $manifest = $this->manifest();

        if (!isset($manifest[$path]['file'])) {
            throw new RuntimeException(
                "Asset [{$path}] não existe no manifest do Vite ({$this->manifestFile()})."
            );
        }

        return $this->url((string) $manifest[$path]['file']);
    }

    // ------------------------------------------------------------------- CSP

    /**
     * Fontes que precisam entrar na CSP enquanto o dev server está no ar, e um
     * array vazio quando não está — a decisão inteira mora em hotUrl().
     *
     * @return array<string,list<string>> diretiva => fontes a acrescentar
     */
    public function cspSources(): array
    {
        $origin = $this->hotUrl();

        if ($origin === null) {
            return [];
        }

        // http -> ws, https -> wss
        $websocket = 'ws' . substr($origin, 4);

        return [
            // O client e cada módulo transformado são <script type="module">
            // servidos pelo dev server.
            'script-src' => [$origin],
            // O client cria elementos <style> no DOM a cada atualização de CSS, e
            // o overlay de erro monta um shadow DOM com <style>. Os dois são
            // barrados por style-src sem 'unsafe-inline'.
            'style-src' => [$origin, "'unsafe-inline'"],
            // Canal de HMR e o ping de reconexão, que é um fetch.
            'connect-src' => [$origin, $websocket],
            // Imagens e fontes referenciadas pelo CSS servido pelo dev server.
            'img-src' => [$origin, 'data:', 'blob:'],
            'font-src' => [$origin],
        ];
    }

    private function url(string $file): string
    {
        return $this->base() . $this->buildPath . '/' . ltrim($file, '/');
    }

    /** Resolvido no render, e não no construtor: Url::getUrlBase() lê $_SERVER. */
    private function base(): string
    {
        return $this->urlBase ?? Url::getUrlBase();
    }

}
