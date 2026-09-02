<?php
declare(strict_types=1);

namespace NeoFramework\Core\Testing;

use NeoFramework\Core\Mail\MailerInterface;
use PHPUnit\Framework\Assert;

/**
 * Mailer que grava em vez de enviar.
 *
 * Cada `send()` fecha uma mensagem e zera os destinatários: o mailer é fluente
 * e reutilizável, e manter a lista entre envios faria a segunda mensagem herdar
 * os destinatários da primeira — um vazamento que num mailer de verdade manda
 * e-mail para quem não devia receber.
 */
final class FakeMailer implements MailerInterface
{
    /** @var list<array{subject:string,body:string,html:bool,to:list<mixed>,cc:list<mixed>,bcc:list<mixed>,from:?array{0:string,1:string}}> */
    private array $sent = [];

    /** @var list<mixed> */
    private array $to = [];

    /** @var list<mixed> */
    private array $cc = [];

    /** @var list<mixed> */
    private array $bcc = [];

    /** @var array{0:string,1:string}|null */
    private ?array $from = null;

    public function addEmail(...$emails): static
    {
        $this->to = [...$this->to, ...$emails];

        return $this;
    }

    public function addEmailCc(...$emails): static
    {
        $this->cc = [...$this->cc, ...$emails];

        return $this;
    }

    public function addEmailBcc(...$emails): static
    {
        $this->bcc = [...$this->bcc, ...$emails];

        return $this;
    }

    public function setFrom(string $email, string $nome = 'Site'): static
    {
        $this->from = [$email, $nome];

        return $this;
    }

    public function send(string $assunto, string $mensagem, bool $isHtml = false): bool
    {
        $this->sent[] = [
            'subject' => $assunto,
            'body' => $mensagem,
            'html' => $isHtml,
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'from' => $this->from,
        ];

        $this->to = [];
        $this->cc = [];
        $this->bcc = [];
        $this->from = null;

        return true;
    }

    /**
     * Afirma que uma mensagem foi enviada.
     *
     * @param callable(array{subject:string,body:string,html:bool,to:list<mixed>,cc:list<mixed>,bcc:list<mixed>,from:?array{0:string,1:string}}):bool|null $filter
     */
    public function assertSent(?callable $filter = null): void
    {
        foreach ($this->sent as $message) {
            if ($filter === null || $filter($message)) return;
        }

        Assert::fail($filter === null ? 'Nenhum e-mail foi enviado.' : 'Nenhum e-mail enviado corresponde ao esperado.');
    }

    public function assertSentTo(string $address): void
    {
        $this->assertSent(function (array $message) use ($address): bool {
            foreach ($message['to'] as $recipient) {
                if ($recipient === $address) return true;
                if (is_array($recipient) && ($recipient[0] ?? null) === $address) return true;
            }

            return false;
        });
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->sent, 'Nenhum e-mail deveria ter sido enviado.');
    }

    public function assertSentTimes(int $times): void
    {
        Assert::assertCount($times, $this->sent, "Esperado {$times} e-mail(s).");
    }

    /** @return list<array{subject:string,body:string,html:bool,to:list<mixed>,cc:list<mixed>,bcc:list<mixed>,from:?array{0:string,1:string}}> */
    public function sent(): array
    {
        return $this->sent;
    }
}
