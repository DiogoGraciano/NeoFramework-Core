<?php
declare(strict_types=1);

namespace NeoFramework\Core\Http;

use NeoFramework\Core\Attributes\FromBody;
use NeoFramework\Core\Attributes\FromHeader;
use NeoFramework\Core\Attributes\FromQuery;
use NeoFramework\Core\Attributes\FromRoute;
use NeoFramework\Core\Attributes\ListOf;
use NeoFramework\Core\Attributes\Sensitive;
use NeoFramework\Core\Validation\ValidationRule;

/**
 * Plano de bind por classe de DTO.
 *
 * Memoizado por processo e, quando compilado, carregado de disco — sob PHP-FPM
 * o memo de processo não sobrevive a uma requisição, que é justamente onde o
 * custo de Reflection aparece em toda chamada.
 */
final class DtoMetadata
{
    /** @var array<class-string, list<DtoParameter>> */
    private static array $plans = [];

    private static bool $preloaded = false;

    private function __construct()
    {
    }

    /** @return list<DtoParameter> */
    public static function of(string $class): array
    {
        // O mapa compilado entra na primeira consulta, e não no bootstrap: assim
        // CLI, worker e requisição HTTP pegam o mesmo caminho sem fiação extra,
        // e quem nunca faz bind não paga o include.
        if (!self::$preloaded) {
            self::$preloaded = true;
            self::preload(DtoCache::load() ?? []);
        }

        return self::$plans[$class] ??= self::build($class);
    }

    /** @return list<DtoParameter> */
    private static function build(string $class): array
    {
        $constructor = (new \ReflectionClass($class))->getConstructor()
            ?? throw new \LogicException("DTO {$class} needs a constructor.");

        $plan = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $rules = [];

            foreach ($parameter->getAttributes() as $attribute) {
                if (!is_subclass_of($attribute->getName(), ValidationRule::class)) continue;
                $instance = $attribute->newInstance();
                if ($instance instanceof ValidationRule) $rules[] = $instance;
            }

            $listOf = $parameter->getAttributes(ListOf::class)[0] ?? null;
            [$source, $sourceKey] = self::sourceOf($parameter);

            $plan[] = new DtoParameter(
                $parameter->getName(),
                $type instanceof \ReflectionNamedType ? $type->getName() : null,
                $parameter->allowsNull(),
                $parameter->isDefaultValueAvailable(),
                $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                $parameter->getAttributes(Sensitive::class) !== [],
                $listOf?->newInstance()->type,
                $rules,
                $source,
                $sourceKey,
            );
        }

        return $plan;
    }

    /**
     * Especificação serializável de uma classe.
     *
     * As regras viram `[classe, argumentos]` em vez de objetos: `var_export` de
     * instância arbitrária exigiria `__set_state` em toda regra, inclusive nas
     * de terceiros. Reinstanciar a partir dos argumentos não usa Reflection.
     *
     * @return list<array<string,mixed>>|null null quando a classe não é compilável
     */
    public static function compile(string $class): ?array
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        if ($constructor === null) return null;

        $spec = [];

        foreach ($constructor->getParameters() as $parameter) {
            $default = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
            if (!self::isExportable($default)) return null;

            $rules = [];
            foreach ($parameter->getAttributes() as $attribute) {
                if (!is_subclass_of($attribute->getName(), ValidationRule::class)) continue;

                // Argumento nomeado em atributo vira chave string, e `new $c(...$args)`
                // aceita isso: exigir lista recusaria `#[Length(min: 3)]`.
                $arguments = $attribute->getArguments();
                if (!self::isExportable($arguments)) return null;

                $rules[] = ['class' => $attribute->getName(), 'arguments' => $arguments];
            }

            $type = $parameter->getType();
            $listOf = $parameter->getAttributes(ListOf::class)[0] ?? null;
            [$source, $sourceKey] = self::sourceOf($parameter);

            $spec[] = [
                'name' => $parameter->getName(),
                'type' => $type instanceof \ReflectionNamedType ? $type->getName() : null,
                'allowsNull' => $parameter->allowsNull(),
                'hasDefault' => $parameter->isDefaultValueAvailable(),
                'default' => $default,
                'sensitive' => $parameter->getAttributes(Sensitive::class) !== [],
                'listElementType' => $listOf?->newInstance()->type,
                'rules' => $rules,
                'source' => $source,
                'sourceKey' => $sourceKey,
            ];
        }

        return $spec;
    }

    /**
     * Semeia o memo com o mapa compilado.
     *
     * @param array<class-string, list<array<string,mixed>>> $compiled
     */
    public static function preload(array $compiled): void
    {
        foreach ($compiled as $class => $spec) {
            $plan = [];

            foreach ($spec as $parameter) {
                /** @var list<ValidationRule> $rules */
                $rules = [];
                foreach ($parameter['rules'] as $rule) {
                    $instance = new $rule['class'](...$rule['arguments']);
                    if ($instance instanceof ValidationRule) $rules[] = $instance;
                }

                $plan[] = new DtoParameter(
                    $parameter['name'],
                    $parameter['type'],
                    $parameter['allowsNull'],
                    $parameter['hasDefault'],
                    $parameter['default'],
                    $parameter['sensitive'],
                    $parameter['listElementType'],
                    $rules,
                    $parameter['source'],
                    $parameter['sourceKey'],
                );
            }

            self::$plans[$class] = $plan;
        }
    }

    public static function reset(): void
    {
        self::$plans = [];
        self::$preloaded = false;
    }

    /**
     * Origem declarada do parâmetro, já resolvida para nome de chave.
     *
     * @return array{0:?string,1:?string}
     */
    private static function sourceOf(\ReflectionParameter $parameter): array
    {
        foreach ($parameter->getAttributes() as $attribute) {
            $name = $attribute->getName();

            if ($name === FromHeader::class) return ['header', $attribute->newInstance()->name];
            if ($name === FromQuery::class) return ['query', $attribute->newInstance()->key ?? $parameter->getName()];
            if ($name === FromRoute::class) return ['route', $attribute->newInstance()->key ?? $parameter->getName()];
            if ($name === FromBody::class) return ['body', $attribute->newInstance()->key ?? $parameter->getName()];
        }

        return [null, null];
    }

    /** `var_export` não reproduz objeto sem `__set_state`; enum ele exporta. */
    private static function isExportable(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::isExportable($item)) return false;
            }

            return true;
        }

        return $value === null || is_scalar($value) || $value instanceof \UnitEnum;
    }
}
