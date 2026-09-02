<?php
declare(strict_types=1);

namespace NeoFramework\Core\Attributes;

use NeoFramework\Core\Validation\RuleSpec;
use NeoFramework\Core\Validation\ValidationRule;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Length implements ValidationRule
{
    public function __construct(public ?int $min = null, public ?int $max = null) {}

    public function spec(): RuleSpec
    {
        return new RuleSpec('length', [$this->min, $this->max], 'Length is invalid.');
    }
}
