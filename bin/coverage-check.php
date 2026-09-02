<?php

declare(strict_types=1);

[$script, $directory, $minimum] = array_pad($argv, 3, null);
if (!is_string($directory) || !is_string($minimum) || !is_numeric($minimum)) {
    fwrite(STDERR, "Uso: php {$script} <diretório-cobertura> <mínimo-percentual>\n");
    exit(2);
}

$index = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'index.xml';
$xml = file_get_contents($index);
if ($xml === false || preg_match_all('/<file [^>]*href="([^"]+)"/', $xml, $matches) < 1) {
    fwrite(STDERR, "O índice de cobertura não contém arquivos.\n");
    exit(2);
}

$base = realpath($directory);
if ($base === false) {
    fwrite(STDERR, "O diretório de cobertura não existe.\n");
    exit(2);
}

$executable = 0;
$executed = 0;
foreach (array_unique($matches[1]) as $href) {
    $file = realpath($base . DIRECTORY_SEPARATOR . $href);
    if ($file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
        fwrite(STDERR, "Arquivo de cobertura inválido no índice: {$href}\n");
        exit(2);
    }

    $xml = file_get_contents($file);
    if ($xml === false || preg_match('/<lines[^>]*executable="(\d+)" executed="(\d+)"/', $xml, $match) !== 1) continue;
    $executable += (int) $match[1];
    $executed += (int) $match[2];
}

if ($executable === 0) {
    fwrite(STDERR, "O relatório não contém linhas executáveis.\n");
    exit(2);
}

$percent = 100 * $executed / $executable;
printf("Cobertura de linhas mantidas: %.2f%% (%d/%d)\n", $percent, $executed, $executable);
if ($percent + 0.00001 < (float) $minimum) {
    fwrite(STDERR, "Cobertura mínima de {$minimum}% não atingida.\n");
    exit(1);
}
