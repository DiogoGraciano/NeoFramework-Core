<?php
declare(strict_types=1);
namespace NeoFramework\Core\Http;

use NeoFramework\Core\Exceptions\ValidationException;
use NeoFramework\Core\Validation\RespectValidator;
use NeoFramework\Core\Validation\ValidatorInterface;
use Psr\Http\Message\ServerRequestInterface;

final class DtoBinder
{
    /** Chave do escopo onde ficam os nomes marcados com #[Sensitive]. */
    public const SENSITIVE_KEYS = 'neoframework.sensitive_keys';

    /** @var array<class-string,\ReflectionClass<object>> */
    private static array $classes = [];

    /**
     * @param array<string,string> $routeVariables Necessário para #[FromRoute].
     */
    public static function bind(string $class, ServerRequestInterface $request, array $routeVariables = [], ?ValidatorInterface $validator = null): object
    {
        $sources = new InputSources($request, $routeVariables);

        return self::bindValues($class, static fn (DtoParameter $parameter): mixed => $sources->valueFor($parameter), $validator ?? new RespectValidator());
    }

    /**
     * Faz o bind de um DTO a partir de valores já isolados pelo seu pai.
     *
     * @param array<string,mixed> $values
     */
    private static function bindNested(string $class, array $values, ValidatorInterface $validator): object
    {
        return self::bindValues($class, static fn (DtoParameter $parameter): mixed => $values[$parameter->name] ?? null, $validator);
    }

    /**
     * @param callable(DtoParameter):mixed $valueFor
     */
    private static function bindValues(string $class, callable $valueFor, ValidatorInterface $validator): object
    {
        $args = [];
        $errors = [];
        $sensitive = [];

        foreach (DtoMetadata::of($class) as $parameter) {
            $name = $parameter->name;

            if ($parameter->sensitive) {
                $sensitive[] = $name;
            }

            $value = $valueFor($parameter);

            if ($value === null && $parameter->hasDefault) { $args[] = $parameter->default; continue; }
            if ($value === null && $parameter->allowsNull) { $args[] = null; continue; }

            try {
                $value = self::castNamedOrNull($value, $parameter->type, $parameter->listElementType, $validator);
            } catch (ValidationException $exception) {
                self::addNestedErrors($errors, $name, $exception->errors);
                continue;
            } catch (\Throwable) {
                // Nunca ecoar o valor recebido: a mensagem de erro é uma saída
                // pública e o campo pode ser uma senha.
                $errors[$name][] = 'Invalid value.';
                continue;
            }

            $messages = $validator->validate($value, $parameter->rules, $name);
            if ($messages !== []) $errors[$name] = [...($errors[$name] ?? []), ...$messages];

            if (!isset($errors[$name])) $args[] = $value;
        }

        // Registrado antes de qualquer lançamento: o campo é sensível mesmo
        // quando a validação falha, e é justamente aí que ele tende a vazar.
        self::registerSensitive($sensitive);

        if ($errors !== []) throw new ValidationException($errors);

        return self::reflection($class)->newInstanceArgs($args);
    }

    public static function supports(string $class): bool
    {
        return class_exists($class) && (new \ReflectionClass($class))->isReadOnly();
    }

    /**
     * Publica os nomes sensíveis no escopo da requisição, de onde o Logger os lê.
     *
     * Vai no escopo em vez de num registry estático para não vazar entre
     * requisições num runtime persistente.
     *
     * @param list<string> $names
     */
    private static function registerSensitive(array $names): void
    {
        if ($names === []) return;

        $scope = RequestScopeContext::current();
        if ($scope === null) return;

        $known = $scope->get(self::SENSITIVE_KEYS, []);
        $scope->set(self::SENSITIVE_KEYS, array_values(array_unique([...(is_array($known) ? $known : []), ...$names])));
    }

    private static function castNamedOrNull(mixed $value, ?string $type, ?string $listElementType, ValidatorInterface $validator): mixed
    {
        if ($type === null || $type === 'mixed') return $value;
        if ($value === null) throw new \InvalidArgumentException();

        return self::castNamed($value, $type, $listElementType, $validator);
    }

    private static function castNamed(mixed $value, string $name, ?string $listElementType, ValidatorInterface $validator): mixed
    {
        if (enum_exists($name) && is_subclass_of($name, \BackedEnum::class)) return $name::tryFrom($value) ?? throw new \InvalidArgumentException();
        return match ($name) {
            'string' => is_scalar($value) ? (string) $value : throw new \InvalidArgumentException(),
            'int' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? throw new \InvalidArgumentException(),
            'float' => filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE) ?? throw new \InvalidArgumentException(),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? throw new \InvalidArgumentException(),
            'array' => self::castList($value, $listElementType, $validator),
            \DateTimeImmutable::class => self::castDate($value),
            \DateTime::class => \DateTime::createFromImmutable(self::castDate($value)),
            \DateTimeInterface::class => self::castDate($value),
            default => self::castDto($value, $name, $validator),
        };
    }

    private static function castDto(mixed $value, string $class, ValidatorInterface $validator): mixed
    {
        if (!self::supports($class) || !is_array($value)) throw new \InvalidArgumentException();

        return self::bindNested($class, $value, $validator);
    }

    /** @return list<mixed> */
    private static function castList(mixed $value, ?string $elementType, ValidatorInterface $validator): array
    {
        if (!is_array($value) || !array_is_list($value)) throw new \InvalidArgumentException();
        if ($elementType === null) return $value;

        $result = [];
        $errors = [];
        foreach ($value as $index => $item) {
            try {
                $result[] = self::castByName($item, $elementType, $validator);
            } catch (ValidationException $exception) {
                self::addNestedErrors($errors, (string) $index, $exception->errors);
            } catch (\Throwable) {
                $errors[(string) $index][] = 'Invalid value.';
            }
        }

        if ($errors !== []) throw new ValidationException($errors);

        return $result;
    }

    private static function castByName(mixed $value, string $type, ValidatorInterface $validator): mixed
    {
        if ($type === 'mixed') return $value;
        if ($value === null) throw new \InvalidArgumentException();

        return self::castNamed($value, $type, null, $validator);
    }

    private static function castDate(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) return $value;
        if ($value instanceof \DateTimeInterface) return \DateTimeImmutable::createFromInterface($value);
        if (!is_string($value)) throw new \InvalidArgumentException();

        foreach ([\DateTimeInterface::RFC3339_EXTENDED, \DATE_ATOM] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) return $date;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) return $date;

        throw new \InvalidArgumentException();
    }

    /** @return \ReflectionClass<object> */
    private static function reflection(string $class): \ReflectionClass
    {
        return self::$classes[$class] ??= new \ReflectionClass($class);
    }

    /**
     * @param array<string,list<string>> $target
     * @param array<string,list<string>> $nested
     */
    private static function addNestedErrors(array &$target, string $prefix, array $nested): void
    {
        foreach ($nested as $path => $messages) {
            $fullPath = $prefix . '.' . $path;
            $target[$fullPath] = [...($target[$fullPath] ?? []), ...$messages];
        }
    }
}
