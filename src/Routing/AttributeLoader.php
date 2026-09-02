<?php
declare(strict_types=1);

namespace NeoFramework\Core\Routing;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\FromRoute;
use NeoFramework\Core\Attributes\Middleware;
use NeoFramework\Core\Attributes\RateLimit;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Attributes\RouteFallback;
use NeoFramework\Core\Attributes\RouteHost;
use NeoFramework\Core\Attributes\RoutePrefix;
use NeoFramework\Core\Http\DtoBinder;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

final class AttributeLoader
{
    public function load(array $controllers): RouteCollection
    {
        $collection = new RouteCollection();
        foreach ($controllers as $controller) {
            if (!is_subclass_of($controller, Controller::class)) continue;
            $class = new ReflectionClass($controller);
            $prefixAttribute = $class->getAttributes(RoutePrefix::class)[0] ?? null;
            $prefixInstance = $prefixAttribute?->newInstance();
            $prefix = $prefixInstance->prefix ?? '';
            $namePrefix = $prefixInstance->name ?? '';
            $classMiddleware = $this->middlewareOf($class);
            $classRateLimit = $this->rateLimitOf($class);
            $classHost = $this->hostOf($class);
            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(RouteFallback::class) as $attribute) {
                    $fallback = $attribute->newInstance();
                    $collection->addFallback(new RouteDefinition('/', array_map(strtoupper(...), $fallback->methods), $controller, $method->getName(), null, false, [...$classMiddleware, ...$this->middlewareOf($method)], $this->rateLimitOf($method) ?? $classRateLimit));
                }

                foreach ($method->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                    $route = $attribute->newInstance();
                    $path = $this->join($prefix, $route->getPath());
                    $pattern = PatternCompiler::compile($path);
                    $parameters = $this->boundNames($method);
                    foreach ($pattern['variables'] as $variable) {
                        if (!in_array($variable, $parameters, true)) throw new \LogicException("A rota {$path} declara {{$variable}}, mas {$controller}::{$method->getName()} não possui esse parâmetro.");
                    }
                    $csrf = $route->getValidCsrf() && !$controller::skipCsrfValidation;
                    $name = $route->getName();
                    $host = $this->hostOf($method) ?? $classHost;
                    // As variáveis do host contam como ligadas: a action recebe
                    // {tenant} do host exatamente como recebe do path, e exigir
                    // que ela declare o parâmetro é o mesmo critério.
                    if ($host !== null) {
                        foreach (PatternCompiler::compileHost($host)['variables'] as $variable) {
                            if (!in_array($variable, $parameters, true)) throw new \LogicException("O host {$host} declara {{$variable}}, mas {$controller}::{$method->getName()} não possui esse parâmetro.");
                        }
                    }
                    $collection->add(new RouteDefinition($path, $route->getMethods(), $controller, $method->getName(), $name === null ? null : $namePrefix . $name, $csrf, [...$classMiddleware, ...$this->middlewareOf($method)], $this->rateLimitOf($method) ?? $classRateLimit, $host, $route->getPriority(), $route->getDefaults()));
                }
            }
        }
        return $collection;
    }

    /**
     * Nomes que a action consegue receber de uma variável de rota.
     *
     * Além dos próprios parâmetros, entram os campos que um DTO declara com
     * `#[FromRoute]`: nesse caso a action não tem o parâmetro, mas a variável
     * continua sendo consumida — recusá-la aqui seria um falso positivo.
     *
     * @return list<string>
     */
    private function boundNames(ReflectionMethod $method): array
    {
        $names = [];

        foreach ($method->getParameters() as $parameter) {
            $names[] = $parameter->getName();

            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || !DtoBinder::supports($type->getName())) continue;

            $constructor = (new ReflectionClass($type->getName()))->getConstructor();
            if ($constructor === null) continue;

            foreach ($constructor->getParameters() as $field) {
                foreach ($field->getAttributes(FromRoute::class) as $attribute) {
                    $names[] = $attribute->newInstance()->key ?? $field->getName();
                }
            }
        }

        return array_values(array_unique($names));
    }

    private function hostOf(ReflectionClass|ReflectionMethod $reflection): ?string
    {
        $attribute = $reflection->getAttributes(RouteHost::class)[0] ?? null;

        return $attribute?->newInstance()->pattern;
    }

    private function middlewareOf(ReflectionClass|ReflectionMethod $reflection): array
    {
        $result = [];
        foreach ($reflection->getAttributes(Middleware::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) $result = [...$result, ...$attribute->newInstance()->classes];
        return $result;
    }

    /** @return array{limit?:int,window?:int,key?:string,policy?:string}|null */
    private function rateLimitOf(ReflectionClass|ReflectionMethod $reflection): ?array
    {
        $attribute = $reflection->getAttributes(RateLimit::class)[0] ?? null;

        return $attribute?->newInstance()->toArray();
    }

    private function join(string $prefix, string $path): string
    {
        if ($path === '' || $path[0] !== '/') throw new \InvalidArgumentException("O path da rota deve começar com '/': {$path}");
        $joined = '/' . trim($prefix, '/') . '/' . ltrim($path, '/');
        $joined = preg_replace('~/+~', '/', $joined) ?: '/';
        return $joined !== '/' ? rtrim($joined, '/') : '/';
    }
}
