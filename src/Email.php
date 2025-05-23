<?php 

namespace NeoFramework\Core;

use PHPMailer\PHPMailer\PHPMailer;

class Email{

    private PHPMailer $email;
    private array $emailsTo = [];
    private array $emailsCc = [];
    private array $emailsBcc = [];
    private bool $from = false;

    public function __construct()
    {
        $this->email = new PHPMailer(true);
    }

    public function addEmailCc(...$emails):email
    {
        $this->emailsCc = array_merge($this->emailsCc,$emails);
        return $this;
    }
    
    public function addEmailBcc(...$emails):email
    {
        $this->emailsBcc = array_merge($this->emailsBcc,$emails);
        return $this;
    }

    public function addEmail(...$emails):email
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

    public function setFrom(string $email,string $nome = "Site"){
        $this->email->setFrom($email,$nome);
        $this->from = true;
        return $this;
    }

    public function debug(){
        $this->email->SMTPDebug = 1;
        $this->email->Debugoutput = "echo";
        return $this;
    }

    public function send($assunto,$mensagem,$isHtml = false):bool
    {
        if(!env("SMTP_SERVIDOR") || !env("SMTP_PORT")){
            return false;
        }

        $this->email->CharSet = "UTF-8";
        $this->email->setLanguage("pt_br");
        $this->email->isSMTP();
        $this->email->Host = env("SMTP_SERVIDOR");
        if(env("SMTP_USUARIO") && env("SMTP_SENHA"))
        {
            $this->email->SMTPAuth    = true;
            $this->email->Username    = env("SMTP_USUARIO");
            $this->email->Password    = env("SMTP_SENHA");
        }

        if(env("SMTP_ENCRYPTION"))
        {
            $this->email->SMTPSecure = env("SMTP_ENCRYPTION");
        }
            
        $this->email->Port = env("SMTP_PORT");

        if(!$this->from)
            $this->email->setFrom(env("SMTP_EMAIL"), env("SMTP_NOME"));

        if(!$this->emailsTo){
            $this->email->addAddress(env("SMTP_EMAIL"), env("SMTP_NOME"));
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
