<?php

namespace NeoFramework\Core\Commands;

use Ahc\Cli\Helper\Shell;
use Ahc\Cli\Input\Command;
use NeoFramework\Core\Functions;

class Test extends Command
{
    public function __construct()
    {
        parent::__construct('test', 'Wrapper for PHPUnit tests');

        $this->argument('<args...>', 'Argumentos adicionais para o PHPUnit', false);
    }

    public function execute(array $args): void
    {
        $phpunitPath = Functions::getRoot() . 'vendor/bin/phpunit';
        $command = escapeshellcmd($phpunitPath) . ' ' . implode(' ', array_map('escapeshellarg', $args));

        $shell = new Shell($command);
        $shell->execute();
    }
}
