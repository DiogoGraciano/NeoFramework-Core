<?php
declare(strict_types=1);

namespace NeoFramework\Core\Http;

use NeoFramework\Core\Validation\ValidationRule;

/**
 * Tudo que o binder precisa saber sobre um parâmetro de DTO.
 *
 * Existe para que o bind não repita Reflection. Ler os atributos de um
 * parâmetro custa uma instanciação por atributo, e isso acontecia quatro vezes
 * por parâmetro em toda requisição — `Sensitive`, `ListOf`, as regras e, no
 * `InputSources`, a origem.
 *
 * `source` é `header`, `query`, `route`, `body` ou null para a heurística padrão.
 */
final readonly class DtoParameter
{
    /** @param list<ValidationRule> $rules */
    public function __construct(
        public string $name,
        public ?string $type,
        public bool $allowsNull,
        public bool $hasDefault,
        public mixed $default,
        public bool $sensitive,
        public ?string $listElementType,
        public array $rules,
        public ?string $source = null,
        public ?string $sourceKey = null,
    ) {}
}
