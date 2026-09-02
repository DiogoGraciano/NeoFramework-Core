<?php
declare(strict_types=1);

namespace NeoFramework\Core\Template;

/** Integer token identifiers kept scalar so compiled templates remain cacheable PHP arrays. */
final class TokenKind
{
    public const VARIABLE = 0;
    public const BLOCK = 1;
    public const PROPERTY = 2;
    public const MODIFIER = 3;
    public const FILE = 4;

    private function __construct()
    {
    }
}
