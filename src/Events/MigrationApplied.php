<?php

declare(strict_types=1);

namespace NeoFramework\Core\Events;

/** Uma migração foi aplicada. Em `--dry-run`, teria sido. */
final readonly class MigrationApplied
{
    public function __construct(
        public string $tag,
        public int $statementCount,
        public bool $dryRun,
        public bool $resumed = false,
    ) {
    }
}
