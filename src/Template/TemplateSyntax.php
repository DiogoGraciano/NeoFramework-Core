<?php
declare(strict_types=1);

namespace NeoFramework\Core\Template;

final class TemplateSyntax
{
    public const BLOCK_MARKER = '__neoblk_';
    public const FINALLY_KEY = "\0finally\0";
    public const TOKEN_PATTERN = '/\{([A-Za-z0-9_]+)((?:->[A-Za-z0-9_]+)*)(\|[^}]*)?\}/';
    public const BLOCK_PATTERN = '/<!--\s*(BEGIN|END)\s+([A-Za-z0-9_]+)\s*-->/';
    public const MODIFIER_ARGUMENT_SEPARATOR = '!';

    private function __construct()
    {
    }
}
