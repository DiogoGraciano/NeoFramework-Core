<?php

declare(strict_types=1);

namespace NeoFramework\Core\Commands\Model;

use Ahc\Cli\Input\Command;
use Diogodg\Neoorm\Codegen\FileSystemWriter;
use Diogodg\Neoorm\Codegen\Generator;
use Diogodg\Neoorm\Codegen\RefAnnotationChecker;
use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\Schema\ModelSchemaLoader;
use NeoFramework\Core\Commands\ExitCode;
use NeoFramework\Core\Support\ConsoleOutput;
use Throwable;

/**
 * `model:types` — gera as classes tipadas dos models.
 *
 * Sucede o `model:phpdoc`, que anotava `@property` para descrever o `__get` mágico do `Db`.
 * Sem o `Db`, um model não tem propriedade nenhuma e a anotação viraria ficção — então em
 * vez de anotar, este comando produz classes de verdade: linhas `final readonly`, payloads
 * de insert com argumentos nomeados e referências de coluna. O autocomplete passa a vir de
 * propriedades declaradas, e o analisador estático verifica.
 *
 * **Não abre conexão**: lê o diretório de models e escreve arquivos. É o que o torna viável
 * como hook de pre-commit e como gate de CI, via `--check`.
 */
class Types extends Command
{
    public function __construct()
    {
        parent::__construct('model:types', 'Generate the typed row, insert and table classes from the models');

        $this->version('2.0')
            ->option('-c --check', 'Write nothing; exit 1 if the generated code drifted from the models')
            ->option('-d --dry-run', 'Show what would be written without writing it');
    }

    public function execute(?bool $check, ?bool $dryRun): int
    {
        $output = new ConsoleOutput();

        try {
            $loader = new ModelSchemaLoader(Config::getPathModel(), Config::getModelNamespace());
            $files = (new Generator(Config::getGeneratedNamespace()))->generate($loader->load());
            $writer = new FileSystemWriter(Config::getPathGenerated());

            if ($check) {
                $drift = $writer->check($files);

                // A anotação `@extends Model<XTable>` entra no mesmo gate: ela é o que dá
                // tipo concreto a `Model::ref()`, é só um comentário, e uma errada faz a
                // IDE concordar com colunas que não existem.
                $annotations = (new RefAnnotationChecker(Config::getGeneratedNamespace()))
                    ->check($loader->modelClasses());

                if ($drift->isClean() && $annotations === []) {
                    $output->success($drift->summary());

                    return ExitCode::OK;
                }

                if (!$drift->isClean()) {
                    $output->error($drift->summary());
                }

                if ($annotations !== []) {
                    $output->error("Anotação de tipo divergente nos models:\n  " . implode("\n  ", $annotations));
                }

                return ExitCode::FAILURE;
            }

            $report = $writer->write($files, (bool) $dryRun);

            $output->success(($dryRun ? '[dry-run] ' : '') . $report->summary());

            return ExitCode::OK;
        } catch (Throwable $e) {
            $output->error($e->getMessage());

            return ExitCode::FAILURE;
        }
    }
}
