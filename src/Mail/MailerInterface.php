<?php
declare(strict_types=1);

namespace NeoFramework\Core\Mail;

/**
 * Contrato de envio de e-mail.
 *
 * Existe para que um teste possa afirmar "este e-mail foi enviado, com este
 * assunto, para este destinatário" sem abrir conexão SMTP. Sem o contrato, a
 * única alternativa é um servidor de verdade — lento, dependente de rede e
 * capaz de mandar mensagem para gente real a partir de uma suíte.
 */
interface MailerInterface
{
    public function addEmail(...$emails): static;

    public function addEmailCc(...$emails): static;

    public function addEmailBcc(...$emails): static;

    public function setFrom(string $email, string $nome = 'Site'): static;

    public function send(string $assunto, string $mensagem, bool $isHtml = false): bool;
}
