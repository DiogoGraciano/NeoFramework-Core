<?php
declare(strict_types=1);

namespace NeoFramework\Core\Storage;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Navega a árvore de uploads de um multipart.
 *
 * `getUploadedFiles()` é aninhado sempre que o formulário usa `documentos[0][arquivo]`.
 * Sem um acessor por caminho, cada chamador reimplementa o passeio pelos níveis —
 * e erra o caso do índice numérico.
 */
final class UploadedFiles
{
    private function __construct() {}

    /** `UploadedFiles::get($request, 'documentos.0.arquivo')` */
    public static function get(ServerRequestInterface $request, string $path): ?UploadedFileInterface
    {
        $node = $request->getUploadedFiles();

        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) return null;
            $node = $node[$segment];
        }

        return $node instanceof UploadedFileInterface ? $node : null;
    }

    /**
     * Achata a árvore em caminhos pontilhados, na ordem em que aparecem.
     *
     * @return array<string,UploadedFileInterface>
     */
    public static function flatten(ServerRequestInterface $request): array
    {
        return self::walk($request->getUploadedFiles(), '');
    }

    /**
     * @param array<array-key,mixed> $node
     * @return array<string,UploadedFileInterface>
     */
    private static function walk(array $node, string $prefix): array
    {
        $files = [];

        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if ($value instanceof UploadedFileInterface) $files[$path] = $value;
            elseif (is_array($value)) $files = [...$files, ...self::walk($value, $path)];
        }

        return $files;
    }
}
