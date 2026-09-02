<?php
declare(strict_types=1);

namespace NeoFramework\Core;

use NeoFramework\Core\Config\MailConfig;
use NeoFramework\Core\Mail\MailerInterface;
use PHPMailer\PHPMailer\PHPMailer;

class Email implements MailerInterface {

    private PHPMailer $email;
    private array $emailsTo = [];
    private array $emailsCc = [];
    private array $emailsBcc = [];
    private bool $from = false;

    public function __construct(private readonly MailConfig $config)
    {
        $this->email = new PHPMailer(true);
    }

    public function addEmailCc(...$emails): static
    {
        $this->emailsCc = array_merge($this->emailsCc,$emails);
        return $this;
    }

    public function addEmailBcc(...$emails): static
    {
        $this->emailsBcc = array_merge($this->emailsBcc,$emails);
        return $this;
    }

    public function addEmail(...$emails): static
    {
        if(!$this->emailsTo){
            if(is_array($emails[0]))
                $this->email->addReplyTo($emails[0][0],$emails[0][1]);
            else
                $this->email->addReplyTo($emails[0]);
        }

        $this->emailsTo = array_merge($this->emailsTo,$emails);
        return $this;
    }

    public function setFrom(string $email, string $nome = "Site"): static {
        $this->email->setFrom($email,$nome);
        $this->from = true;
        return $this;
    }

    public function debug(){
        $this->email->SMTPDebug = 1;
        $this->email->Debugoutput = "echo";
        return $this;
    }

    public function send(string $assunto, string $mensagem, bool $isHtml = false): bool
    {
        if (!$this->config->isConfigured()) {
            return false;
        }

        $this->email->CharSet = "UTF-8";
        $this->email->setLanguage("pt_br");
        $this->email->isSMTP();
        $this->email->Host = $this->config->host;
        if ($this->config->username !== '' && $this->config->password !== '')
        {
            $this->email->SMTPAuth = true;
            $this->email->Username = $this->config->username;
            $this->email->Password = $this->config->password;
        }

        if ($this->config->encryption !== '')
        {
            $this->email->SMTPSecure = $this->config->encryption;
        }

        $this->email->Port = $this->config->port;

        if(!$this->from)
            $this->email->setFrom($this->config->fromAddress, $this->config->fromName);

        if(!$this->emailsTo){
            $this->email->addAddress($this->config->fromAddress, $this->config->fromName);
        }

        foreach ($this->emailsTo as $email){
            if(is_array($email) && isset($email[1])){
                $this->email->addAddress($email[0], $email[1]);
            }

            if(!is_array($email)){
                $this->email->addAddress($email);
            }
        }

        foreach ($this->emailsCc as $email){
            $this->email->addCC($email);
        }

        foreach ($this->emailsBcc as $email){
            $this->email->addBCC($email);
        }

        $this->email->Subject = $assunto;
        $this->email->isHTML($isHtml);
        $this->email->Body = $mensagem;

        if ($this->email->send()) {
            return true;
        }

        return false;
    }
}

?>
