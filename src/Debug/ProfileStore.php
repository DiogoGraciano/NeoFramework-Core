<?php

declare(strict_types=1);

namespace NeoFramework\Core\Debug;

use NeoFramework\Core\Support\ProjectRoot;

/**
 * Guarda os perfis coletados em disco, com teto.
 *
 * Um arquivo por requisição, e não um só acumulando: duas requisições
 * concorrentes escrevendo no mesmo arquivo se perderiam, e é justamente sob
 * concorrência que o profiler é útil.
 */
final class ProfileStore
{
    public function __construct(private readonly int $limit = 50)
    {
    }

    public function save(Profile $profile): void
    {
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) return;

        $file = $directory . DIRECTORY_SEPARATOR . $profile->token . '.json';
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, json_encode($profile, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) return;
        @rename($tmp, $file);

        $this->prune();
    }

    public function find(string $token): ?Profile
    {
        // O token vem da URL: qualquer coisa fora de hexadecimal poderia sair do
        // diretório com "../" e transformar o profiler num leitor de arquivos.
        if (preg_match('/^[0-9a-f]{16}$/', $token) !== 1) return null;

        $file = self::directory() . DIRECTORY_SEPARATOR . $token . '.json';
        if (!is_file($file)) return null;

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? Profile::fromArray($data) : null;
    }

    /** @return list<Profile> mais recentes primeiro */
    public function recent(int $limit = 20): array
    {
        $profiles = [];
        foreach (glob(self::directory() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) $profiles[] = Profile::fromArray($data);
        }

        usort($profiles, static fn (Profile $a, Profile $b): int => $b->collectedAt <=> $a->collectedAt);

        return array_slice($profiles, 0, $limit);
    }

    public function clear(): void
    {
        foreach (glob(self::directory() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) @unlink($file);
    }

    /** Apaga os mais antigos além do teto, para o diretório não crescer sem fim. */
    private function prune(): void
    {
        $files = glob(self::directory() . DIRECTORY_SEPARATOR . '*.json') ?: [];
        if (count($files) <= $this->limit) return;

        usort($files, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));
        foreach (array_slice($files, 0, count($files) - $this->limit) as $file) @unlink($file);
    }

    public static function directory(): string
    {
        return ProjectRoot::path() . 'Cache' . DIRECTORY_SEPARATOR . 'profiler';
    }
}
