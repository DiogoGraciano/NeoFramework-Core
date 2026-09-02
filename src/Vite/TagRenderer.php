<?php
declare(strict_types=1);

namespace NeoFramework\Core\Vite;

/** Produces escaped Vite HTML tags without reading configuration or the manifest. */
final class TagRenderer
{
    /** @param list<string> $entrypoints */
    public function hot(string $origin, array $entrypoints): string
    {
        $tags = [$this->script($origin . '/@vite/client')];
        foreach ($entrypoints as $entrypoint) {
            $url = $origin . '/' . ltrim($entrypoint, '/');
            $tags[] = $this->isStylesheet($entrypoint) ? $this->stylesheet($url) : $this->script($url);
        }

        return implode('', $tags);
    }

    /** @param list<string> $stylesheets @param list<string> $preloads @param list<string> $scripts */
    public function build(array $stylesheets, array $preloads, array $scripts): string
    {
        $html = '';
        foreach ($stylesheets as $href) {
            $html .= $this->stylesheet($href);
        }
        foreach ($preloads as $href) {
            $html .= $this->modulePreload($href);
        }
        foreach ($scripts as $src) {
            $html .= $this->script($src);
        }

        return $html;
    }

    public function isStylesheet(string $path): bool
    {
        $path = (string) strtok($path, '?');

        return AssetType::isStylesheet($path);
    }

    private function script(string $src): string
    {
        return '<script type="module" src="' . $this->escape($src) . '"></script>';
    }

    private function stylesheet(string $href): string
    {
        return '<link rel="stylesheet" href="' . $this->escape($href) . '">';
    }

    private function modulePreload(string $href): string
    {
        return '<link rel="modulepreload" href="' . $this->escape($href) . '">';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
