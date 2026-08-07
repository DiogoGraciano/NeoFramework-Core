<?php

namespace NeoFramework\Core\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Route
{
    /**
     * @param string $path  Padrão da rota.
     * @param array  $methods Métodos HTTP aceitos.
     * @param bool   $validCsrf Exige token CSRF nos métodos que alteram estado.
     *                          Métodos seguros (GET/HEAD/OPTIONS) nunca são
     *                          validados; a decisão é tomada por requisição,
     *                          no Router, e não pela rota inteira.
     */
    public function __construct(
        private string $path,
        private array $methods = ['GET'],
        private bool $validCsrf = true
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
}
