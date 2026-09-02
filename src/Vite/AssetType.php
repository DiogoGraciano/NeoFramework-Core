<?php
declare(strict_types=1);

namespace NeoFramework\Core\Vite;

final class AssetType
{
    private const STYLE_EXTENSIONS = ['css', 'scss', 'sass', 'less', 'styl', 'stylus', 'pcss', 'postcss'];

    private function __construct()
    {
    }

    public static function isStylesheet(string $path): bool
    {
        $path = (string) strtok($path, '?');

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::STYLE_EXTENSIONS, true);
    }
}
