<?php
declare(strict_types=1);

namespace NeoFramework\Core\Template;

/** Renders a compiled template body in one deterministic substitution pass. */
final class TemplateRenderer
{
    /** @param array<string,array<mixed>> $tokens */
    public function render(string $body, array $tokens, TokenResolverInterface $resolver): string
    {
        if ($tokens === []) {
            return $body;
        }

        $replacements = [];
        foreach ($tokens as $placeholder => $token) {
            $replacements[$placeholder] = $resolver->resolveToken($token);
        }

        return strtr($body, $replacements);
    }
}
