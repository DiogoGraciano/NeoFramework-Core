<?php
declare(strict_types=1);

namespace NeoFramework\Core\Template;

use InvalidArgumentException;
use UnexpectedValueException;

/** Compiles template source into cacheable bodies, token descriptors and block topology. */
final class TemplateCompiler
{
    public function __construct(private readonly bool $accurate)
    {
    }

    /** @return array{body:array,keys:array,children:array,finally:array,vars:array,blocks:array} */
    public function compile(string $filename): array
    {
        $source = preg_replace('/<!---.*?--->/s', '', (string) file_get_contents($filename));
        if ($source === null || $source === '') {
            throw new InvalidArgumentException("file $filename is empty");
        }

        $variables = [];
        if (preg_match_all(TemplateSyntax::TOKEN_PATTERN, $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $variables[$match[1]] = true;
            }
        }

        $tree = [];
        $stack = [];
        if (preg_match_all(TemplateSyntax::BLOCK_PATTERN, $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if ($match[1] === 'BEGIN') {
                    $tree[$stack === [] ? '.' : end($stack)][] = $match[2];
                    $stack[] = $match[2];
                } else {
                    array_pop($stack);
                }
            }
        }

        $bodies = ['.' => $source];
        $blocks = [];
        $children = [];
        $finally = [];

        foreach ($tree as $parent => $list) {
            foreach ($list as $block) {
                if (isset($blocks[$block])) {
                    throw new UnexpectedValueException("duplicated block: $block");
                }

                $blocks[$block] = true;
                $children[$parent][] = $block;
                [$body, $finallyBody, $bodies[$parent]] = $this->splitBlock($bodies[$parent], $block);
                $bodies[$block] = $body;

                if ($finallyBody !== null) {
                    $bodies[TemplateSyntax::FINALLY_KEY . $block] = $finallyBody;
                    $finally[$block] = true;
                }
            }
        }

        $keys = [];
        foreach ($bodies as $name => $body) {
            $keys[$name] = $this->extractKeys($body);
        }

        return ['body' => $bodies, 'keys' => $keys, 'children' => $children, 'finally' => $finally, 'vars' => $variables, 'blocks' => $blocks];
    }

    /** @return array{0:string,1:string|null,2:string} */
    private function splitBlock(string $parent, string $block): array
    {
        if ($this->accurate) {
            $parent = str_replace("\r\n", "\n", $parent);
            $pattern = "/\t*<!--\s*BEGIN\s+$block\s+-->\n*(\s*.*?\n?)\t*<!--\s+END\s+$block\s*-->\n*((\s*.*?\n?)\t*<!--\s+FINALLY\s+$block\s*-->\n?)?/sm";
        } else {
            $pattern = "/<!--\s*BEGIN\s+$block\s+-->\s*(\s*.*?\s*)<!--\s+END\s+$block\s*-->\s*((\s*.*?\s*)<!--\s+FINALLY\s+$block\s*-->)?\s*/sm";
        }

        if (preg_match($pattern, $parent, $match, PREG_OFFSET_CAPTURE) !== 1) {
            throw new UnexpectedValueException("mal-formed block $block");
        }

        $updated = substr_replace($parent, '{' . TemplateSyntax::BLOCK_MARKER . $block . '}', $match[0][1], strlen($match[0][0]));
        $finally = isset($match[3]) && $match[3][1] !== -1 ? $match[3][0] : null;

        return [$match[1][0], $finally, $updated];
    }

    /** @return array<string,array> */
    private function extractKeys(string $body): array
    {
        $keys = [];
        if (!preg_match_all(TemplateSyntax::TOKEN_PATTERN, $body, $matches, PREG_SET_ORDER)) {
            return $keys;
        }

        foreach ($matches as $match) {
            $placeholder = $match[0];
            if (isset($keys[$placeholder])) {
                continue;
            }

            $name = $match[1];
            $properties = $match[2];
            $modifiers = $match[3] ?? '';
            $path = $properties === '' ? null : array_slice(explode('->', $properties), 1);

            if ($modifiers === '') {
                $keys[$placeholder] = $path !== null
                    ? [TokenKind::PROPERTY, $name, $path]
                    : (str_starts_with($name, TemplateSyntax::BLOCK_MARKER)
                        ? [TokenKind::BLOCK, substr($name, strlen(TemplateSyntax::BLOCK_MARKER))]
                        : [TokenKind::VARIABLE, $name]);
                continue;
            }

            $chain = [];
            foreach (explode('|', ltrim($modifiers, '|')) as $statement) {
                if ($statement === '') {
                    continue;
                }

                $parts = explode(TemplateSyntax::MODIFIER_ARGUMENT_SEPARATOR, $statement);
                $function = array_shift($parts);
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $function) !== 1) {
                    throw new InvalidArgumentException("invalid modifier name: $function");
                }

                $chain[] = [$function, $parts];
            }

            $keys[$placeholder] = [TokenKind::MODIFIER, $name, $path, $chain];
        }

        return $keys;
    }
}
