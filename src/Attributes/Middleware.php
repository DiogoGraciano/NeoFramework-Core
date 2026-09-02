<?php
declare(strict_types=1);

namespace NeoFramework\Core\Attributes;

use Psr\Http\Server\MiddlewareInterface;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Middleware
{
    /**
     * @param class-string<MiddlewareInterface> ...$classes
     *        Só nome de classe: o mapa de rotas é serializado com var_export,
     *        e uma instância não é exportável. Middleware com parâmetro recebe
     *        os seus pelo container.
     */
    public readonly array $classes;

    public function __construct(string ...$classes)
    {
        foreach ($classes as $class) {
            if (!is_subclass_of($class, MiddlewareInterface::class)) {
                throw new \InvalidArgumentException("Middleware inválido: {$class} deve implementar " . MiddlewareInterface::class . '.');
            }
        }
        $this->classes = $classes;
    }
}
