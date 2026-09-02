<?php
declare(strict_types=1);

namespace NeoFramework\Core\Http;

use NeoFramework\Core\Attributes\CurrentUser;
use NeoFramework\Core\Auth\AuthContext;
use NeoFramework\Core\Exceptions\BadRequestException;
use NeoFramework\Core\Exceptions\UnauthorizedException;
use NeoFramework\Core\Validation\ValidatorInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionMethod;
use ReflectionNamedType;

final class ArgumentResolver
{
    public static function resolve(ReflectionMethod $method, array $variables, ServerRequestInterface $request, ContainerInterface $container): array
    {
        $arguments = [];
        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName(); $type = $parameter->getType();
            if ($parameter->getAttributes(CurrentUser::class) !== []) {
                $identity = AuthContext::current()->identity;
                if ($identity !== null) { $arguments[] = $identity; continue; }
                if ($parameter->allowsNull()) { $arguments[] = null; continue; }
                throw new UnauthorizedException();
            }
            if (array_key_exists($name, $variables)) { $arguments[] = self::cast($variables[$name], $type instanceof ReflectionNamedType ? $type->getName() : null, $name); continue; }
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && is_a($request, $type->getName())) { $arguments[] = $request; continue; }
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && DtoBinder::supports($type->getName())) { $arguments[] = DtoBinder::bind($type->getName(), $request, $variables, self::validator($container)); continue; }
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && $container->has($type->getName())) { $arguments[] = $container->get($type->getName()); continue; }
            if ($parameter->isDefaultValueAvailable()) { $arguments[] = $parameter->getDefaultValue(); continue; }
            if ($parameter->allowsNull()) { $arguments[] = null; continue; }
            throw new \LogicException("Não foi possível resolver {$method->class}::{$method->name}(\${$name}).");
        }
        return $arguments;
    }

    /**
     * O motor de validação é o do container quando a aplicação o substitui, e o
     * padrão do binder caso contrário — testes montam o resolver sem definição.
     */
    private static function validator(ContainerInterface $container): ?ValidatorInterface
    {
        if (!$container->has(ValidatorInterface::class)) return null;

        $validator = $container->get(ValidatorInterface::class);

        return $validator instanceof ValidatorInterface ? $validator : null;
    }

    /**
     * Converte um parâmetro de rota para o tipo declarado na action.
     *
     * O valor veio do cliente, então uma incompatibilidade é 400 — não 500.
     * Um erro do desenvolvedor (rota e action que discordam) continua sendo
     * LogicException lá em cima.
     */
    private static function cast(string $value, ?string $type, string $name): mixed
    {
        return match ($type) {
            'int' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? throw new BadRequestException("O parâmetro \"{$name}\" deve ser um inteiro."),
            'float' => filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE) ?? throw new BadRequestException("O parâmetro \"{$name}\" deve ser um número."),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? throw new BadRequestException("O parâmetro \"{$name}\" deve ser um booleano."),
            default => $type !== null && enum_exists($type) && is_subclass_of($type, \BackedEnum::class)
                ? ($type::tryFrom($value) ?? throw new BadRequestException("O parâmetro \"{$name}\" não é um valor válido."))
                : $value,
        };
    }
}
