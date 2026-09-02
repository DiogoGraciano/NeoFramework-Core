<?php
declare(strict_types=1);

namespace NeoFramework\Core\Enums;

enum FileStorageType
{
    case DOCUMENT;
    case IMAGE;
    case ANY;
}
