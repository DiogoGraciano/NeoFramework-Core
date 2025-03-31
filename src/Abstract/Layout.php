<?php

namespace NeoFramework\Core\Abstract;

use NeoFramework\Core\Functions;
use NeoFramework\Core\Session;
use NeoFramework\Core\Template;

abstract class Layout{

    protected template $tpl;

    public function setTemplate(string $caminho,bool $accurate=false)
    {
        $this->tpl = new Template(Functions::getRoot()."/App/View/Templates/".$caminho,$accurate);
        
        $this->setCsrfToken($this->tpl);
    }

    public function getTemplate(string $caminho,bool $accurate=false):template
    {
        $tpl = new Template(Functions::getRoot()."/App/View/Templates/".$caminho,$accurate); 

        $this->setCsrfToken($tpl);
    
        return $tpl;
    }

    private function setCsrfToken(Template &$tpl)
    {
        if($tpl->exists("neof_csrf_token"))
            $tpl->neof_csrf_token = '<input type="hidden" name="CSRF_TOKEN" value="'.Session::getCsrfToken().'">';
    }

    public function isMobile():bool
    {
        return Functions::isMobile();
    }

    public function show():void
    {
        $this->tpl->show();
    }

    public function parse():string
    {
        return $this->tpl->parse();
    }

}