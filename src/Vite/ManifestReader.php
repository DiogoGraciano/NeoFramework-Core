<?php
declare(strict_types=1);

namespace NeoFramework\Core\Vite;

use RuntimeException;

/** Loads and validates the Vite build manifest once per request/process instance. */
final class ManifestReader
{
    /** @var array<string,array>|null */
    private ?array $data = null;

    public function __construct(private readonly string $file)
    {
    }

    public function file(): string
    {
        return $this->file;
    }

    /** @return array<string,array> */
    public function all(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        if (!is_file($this->file)) {
            throw new RuntimeException(
                "Manifest do Vite não encontrado em {$this->file}. "
                . "Rode `./vendor/bin/neof build` para gerar os assets, ou "
                . "`./vendor/bin/neof vite:dev` para trabalhar com HMR."
            );
        }

        $decoded = json_decode((string) file_get_contents($this->file), true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Manifest do Vite inválido em {$this->file}: " . json_last_error_msg());
        }

        return $this->data = $decoded;
    }
}
