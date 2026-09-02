<?php
declare(strict_types=1);

namespace NeoFramework\Core\Http;

use LogicException;
use Psr\Http\Message\StreamInterface;

/**
 * Um corpo PSR-7 produzido sob demanda por um generator.
 *
 * PSR-7 assume um corpo que já existe; um relatório de um milhão de linhas não
 * cabe nessa suposição. Cada `read()` puxa só o próximo pedaço do generator, de
 * modo que a memória acompanha o bloco, não a resposta.
 */
final class CallbackStream implements StreamInterface
{
    private string $buffer = '';
    private bool $finished = false;

    /** @var \Generator<int,string>|null */
    private ?\Generator $generator = null;

    /** @param callable():\Generator<int,string> $factory */
    public function __construct(private $factory)
    {
    }

    public function read(int $length): string
    {
        while (strlen($this->buffer) < $length && !$this->finished) $this->pull();

        $chunk = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $chunk;
    }

    public function eof(): bool
    {
        return $this->finished && $this->buffer === '';
    }

    public function getContents(): string
    {
        while (!$this->finished) $this->pull();

        $contents = $this->buffer;
        $this->buffer = '';

        return $contents;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    private function pull(): void
    {
        $this->generator ??= ($this->factory)();

        if (!$this->generator->valid()) {
            $this->finished = true;

            return;
        }

        $this->buffer .= $this->generator->current();
        $this->generator->next();
    }

    public function close(): void { $this->finished = true; $this->buffer = ''; }
    public function detach() { $this->close(); return null; }
    public function getSize(): ?int { return null; }
    public function tell(): int { throw new LogicException('Um corpo gerado sob demanda não tem posição.'); }
    public function isSeekable(): bool { return false; }
    public function seek(int $offset, int $whence = SEEK_SET): void { throw new LogicException('Um corpo gerado sob demanda não é navegável.'); }
    public function rewind(): void { throw new LogicException('Um corpo gerado sob demanda não pode voltar ao início.'); }
    public function isWritable(): bool { return false; }
    public function write(string $string): int { throw new LogicException('Um corpo gerado sob demanda é somente leitura.'); }
    public function isReadable(): bool { return true; }
    public function getMetadata(?string $key = null) { return $key === null ? [] : null; }
}
