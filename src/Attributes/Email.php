<?php
declare(strict_types=1);

namespace NeoFramework\Core\Attributes;

use NeoFramework\Core\Validation\RuleSpec;
use NeoFramework\Core\Validation\ValidationRule;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Email implements ValidationRule
{
    public function spec(): RuleSpec
    {
        return new RuleSpec('email', [], 'Must be a valid email address.');
    }
}
