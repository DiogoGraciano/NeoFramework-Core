<?php
declare(strict_types=1);

namespace NeoFramework\Core\Abstract;

use NeoFramework\Core\Session;
use NeoFramework\Core\Template;
use NeoFramework\Core\Vite;

abstract class Layout{

    protected template $tpl;

    /**
     * Entradas do Vite injetadas em {neof_vite}.
     *
     * Vazio usa a lista de Config/vite.config.php. Um layout de outra area
     * declara a sua:
     *
     *     protected array $viteEntrypoints = ['resources/js/admin.js'];
     *
     * @var list<string>
     */
    protected array $viteEntrypoints = [];

    public function setTemplate(string $caminho,bool $accurate = false)
    {
        $this->tpl = new Template($this->templatePath($caminho),$accurate);

        $this->prepare($this->tpl);
    }

    public function getTemplate(string $caminho,bool $accurate = false):template
    {
        $tpl = new Template($this->templatePath($caminho),$accurate);

        $this->prepare($tpl);

        return $tpl;
    }

    /**
     * Caminho absoluto de um template.
     *
     * Protegido para que subclasses (e testes) possam apontar para outro
     * diretorio. getRoot() ja termina em barra, entao nao se acrescenta outra.
     */
    protected function templatePath(string $caminho): string
    {
        return \NeoFramework\Core\Support\ProjectRoot::path() . "App/View/Templates/" . $caminho;
    }

    /** Placeholders que o framework preenche sozinho, quando o documento os declara. */
    private function prepare(Template $tpl): void
    {
        $this->setCsrfToken($tpl);
        $this->setViteTags($tpl);
    }

    private function setCsrfToken(Template $tpl)
    {
        if($tpl->exists("neof_csrf_token"))
            $tpl->neof_csrf_token = '<input type="hidden" name="CSRF_TOKEN" value="' . Session::getCsrfToken() . '">';
    }

    /**
     * Tags de CSS e JS do Vite.
     *
     * A guarda exists() e o que torna isso gratuito para quem nao usa: o
     * placeholder so existe se {neof_vite} aparece literalmente no documento, e
     * atribuir a uma variavel inexistente lancaria.
     */
    private function setViteTags(Template $tpl): void
    {
        if($tpl->exists("neof_vite"))
            $tpl->neof_vite = Vite::tags(...$this->viteEntrypoints);
    }

    public function isMobile():bool
    {
        return \NeoFramework\Core\Support\UserAgent::isMobile((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
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
