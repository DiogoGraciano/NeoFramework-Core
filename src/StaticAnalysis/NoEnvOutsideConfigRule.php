<?php
declare(strict_types=1);

namespace NeoFramework\Core\StaticAnalysis;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/** @implements Rule<FuncCall> */
final class NoEnvOutsideConfigRule implements Rule
{
    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /** @return list<\PHPStan\Rules\RuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Name || strtolower($node->name->toString()) !== 'env') {
            return [];
        }

        $file = str_replace('\\', '/', $scope->getFile());
        if (str_contains($file, '/Config/') || str_ends_with($file, '/src/helpers.php')) {
            return [];
        }

        return [RuleErrorBuilder::message('env() may only be called from Config/*.php; inject a typed configuration object instead.')->identifier('neoframework.envOutsideConfig')->build()];
    }
}
