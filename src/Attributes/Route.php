<?php

namespace NeoFramework\Core\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Route
{
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

        if(in_array("GET",$this->methods)){
            $this->validCsrf = false;
        }
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
