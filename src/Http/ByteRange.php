<?php
declare(strict_types=1);

namespace NeoFramework\Core\Http;

/**
 * Um intervalo de `Range: bytes=` já resolvido contra o tamanho real.
 *
 * O parsing é separado da resposta porque é aqui que mora o risco: um intervalo
 * mal interpretado serve bytes de fora do arquivo ou devolve `Content-Length`
 * que não bate com o corpo, e o cliente trava esperando o resto.
 */
final readonly class ByteRange
{
    private function __construct(public int $start, public int $end)
    {
    }

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }

    public function contentRange(int $size): string
    {
        return "bytes {$this->start}-{$this->end}/{$size}";
    }

    /**
     * @return self|null|false null quando não há Range; false quando é insatisfazível (416)
     */
    public static function parse(string $header, int $size): self|null|false
    {
        if ($header === '') return null;
        if (preg_match('/^bytes=(.+)$/i', trim($header), $matches) !== 1) return null;

        // Múltiplos intervalos são legais, mas exigem multipart/byteranges. Tratar
        // como "sem Range" e devolver 200 é a resposta correta e não mente sobre
        // o corpo enviado.
        $specs = explode(',', $matches[1]);
        if (count($specs) !== 1) return null;

        if (preg_match('/^(\d*)-(\d*)$/', trim($specs[0]), $parts) !== 1) return false;
        [$from, $to] = [$parts[1], $parts[2]];
        if ($from === '' && $to === '') return false;

        if ($size === 0) return false;

        if ($from === '') {
            // `bytes=-500`: os últimos 500 bytes, limitados ao arquivo inteiro.
            $length = (int) $to;
            if ($length === 0) return false;

            return new self(max(0, $size - $length), $size - 1);
        }

        $start = (int) $from;
        if ($start >= $size) return false;

        $end = $to === '' ? $size - 1 : min((int) $to, $size - 1);
        if ($end < $start) return false;

        return new self($start, $end);
    }
}
