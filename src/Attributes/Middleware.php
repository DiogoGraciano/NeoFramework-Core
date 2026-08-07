<?php

namespace NeoFramework\Core\Attributes;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Interfaces\Middleware as InterfacesMiddleware;
use NeoFramework\Core\Response;
use SplQueue;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Middleware
{
    private SplQueue $middleware;

    /**
     * @param class-string<InterfacesMiddleware>|InterfacesMiddleware ...$classes
     *        Aceita tanto o nome da classe (#[Middleware(Auth::class)], a forma
     *        natural num atributo) quanto uma instância já construída.
     */
    public function __construct(...$classes
    ) {
        $this->middleware = new SplQueue;

        foreach ($classes as $class){
            if ($class instanceof InterfacesMiddleware) {
                $this->add($class);
                continue;
            }

            if (is_string($class) && is_subclass_of($class, InterfacesMiddleware::class)) {
                $this->add(new $class());
                continue;
            }

            throw new \InvalidArgumentException(
                "Middleware inválido: cada argumento deve implementar " . InterfacesMiddleware::class . "."
            );
        }
    }

    private function add(InterfacesMiddleware $class){
        $this->middleware->enqueue($class);
    }

    public function handleBefore(Controller $controller){

        foreach ($this->middleware as $middleware){
            $controller = $middleware->before($controller);
        }

        return $controller;
    }

    /**
     * Executa os middlewares "after" na ordem inversa da entrada.
     */
    public function handleAfter(Response $response){

        $reversed = [];
        foreach ($this->middleware as $middleware) {
            array_unshift($reversed, $middleware);
        }

        foreach ($reversed as $middleware) {
            // O retorno era descartado, então nenhum middleware "after"
            // conseguia alterar a resposta.
            $response = $middleware->after($response);
        }

        return $response;
    }
}
