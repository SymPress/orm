<?php

declare(strict_types=1);

namespace SymPress\Orm\Command;

use SymPress\Orm\Schema\SchemaTool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'orm:schema:sql', description: 'Dump SQL generated from ORM entity metadata.')]
final class SchemaSqlCommand extends Command
{
    public function __construct(private readonly SchemaTool $schemaTool)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('manager', InputArgument::OPTIONAL, 'Entity manager/plugin slug to inspect.')
            ->addOption('drop', null, InputOption::VALUE_NONE, 'Dump DROP TABLE statements instead of CREATE TABLE SQL.')
            ->addOption('destructive', null, InputOption::VALUE_NONE, 'Include DROP COLUMN and DROP INDEX statements in update SQL.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manager = $this->optionalString($input->getArgument('manager'));
        $statements = $input->getOption('drop')
            ? $this->schemaTool->getDropSchemaSql($manager)
            : $this->schemaTool->getUpdateSchemaSql($manager, (bool) $input->getOption('destructive'));

        foreach ($statements as $statement) {
            $output->writeln($statement);
            $output->writeln('');
        }

        return Command::SUCCESS;
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
