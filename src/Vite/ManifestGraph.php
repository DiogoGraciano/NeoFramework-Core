<?php
declare(strict_types=1);

namespace NeoFramework\Core\Vite;

use RuntimeException;

/** Resolves static Vite imports into ordered, deduplicated asset buckets. */
final class ManifestGraph
{
    /**
     * @param array<string,array> $manifest
     * @param list<string> $entrypoints
     * @return array{stylesheets:list<string>,preloads:list<string>,scripts:list<string>}
     */
    public function resolve(array $manifest, array $entrypoints, string $manifestFile): array
    {
        $stylesheets = [];
        $preloads = [];
        $scripts = [];
        $visited = [];

        foreach ($entrypoints as $entrypoint) {
            $visited[$entrypoint] = true;
        }

        foreach ($entrypoints as $entrypoint) {
            if (!isset($manifest[$entrypoint])) {
                throw new RuntimeException(
                    "Entrada [{$entrypoint}] não existe no manifest do Vite ({$manifestFile}). "
                    . "Confira 'build.rollupOptions.input' no vite.config.js e a lista de 'entrypoints' "
                    . "em Config/vite.config.php — os dois usam o mesmo caminho."
                );
            }

            $chunk = $manifest[$entrypoint];
            foreach ($chunk['css'] ?? [] as $file) {
                $stylesheets[(string) $file] = true;
            }
            foreach ($chunk['imports'] ?? [] as $import) {
                $this->walk($manifest, (string) $import, $visited, $preloads, $stylesheets);
            }

            $file = (string) ($chunk['file'] ?? '');
            if (AssetType::isStylesheet($file)) {
                $stylesheets[$file] = true;
            } else {
                $scripts[$file] = true;
            }
        }

        return ['stylesheets' => array_keys($stylesheets), 'preloads' => array_keys($preloads), 'scripts' => array_keys($scripts)];
    }

    /** @param array<string,array> $manifest @param array<string,bool> $visited @param array<string,bool> $preloads @param array<string,bool> $stylesheets */
    private function walk(array $manifest, string $key, array &$visited, array &$preloads, array &$stylesheets): void
    {
        if (isset($visited[$key]) || !isset($manifest[$key])) {
            return;
        }

        $visited[$key] = true;
        $chunk = $manifest[$key];
        if (isset($chunk['file'])) {
            $file = (string) $chunk['file'];
            if (AssetType::isStylesheet($file)) {
                $stylesheets[$file] = true;
            } else {
                $preloads[$file] = true;
            }
        }
        foreach ($chunk['css'] ?? [] as $file) {
            $stylesheets[(string) $file] = true;
        }
        foreach ($chunk['imports'] ?? [] as $import) {
            $this->walk($manifest, (string) $import, $visited, $preloads, $stylesheets);
        }
    }
}
