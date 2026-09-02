<?php
declare(strict_types=1);

namespace NeoFramework\Core\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Route
{
    /**
     * @param string $path  Padrão da rota.
     * @param array  $methods Métodos HTTP aceitos.
     * @param bool   $validCsrf Exige token CSRF nos métodos que alteram estado.
     *                          Métodos seguros (GET/HEAD/OPTIONS) nunca são
     *                          validados; a decisão é tomada por requisição,
     *                          no Router, e não pela rota inteira.
     * @param int    $priority Maior é testado antes. Existe para o caso em que
     *                          duas rotas dinâmicas se sobrepõem de propósito e
     *                          a ordem de declaração — que depende da ordem dos
     *                          métodos na classe — não é uma base confiável.
     * @param array<string,string> $defaults Valor de uma variável ausente na URL,
     *                          para placeholders opcionais.
     */
    public function __construct(
        private string $path,
        private array $methods = ['GET'],
        private bool $validCsrf = true,
        private ?string $name = null,
        private int $priority = 0,
        private array $defaults = [],
    ){
        $this->methods = array_map(function($value) {
            if (is_string($value)) {
                return strtoupper($value);
            }
            return $value;
        }, $this->methods);
    }

    public function getMethods():array
    {
        return $this->methods;
    }

    public function getPath():string
    {
        return $this->path;
    }

    public function getValidCsrf():bool
    {
        return $this->validCsrf;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    /** @return array<string,string> */
    public function getDefaults(): array
    {
        return $this->defaults;
    }
}
