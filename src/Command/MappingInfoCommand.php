<?php

declare(strict_types=1);

namespace SymPress\Orm\Command;

use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'orm:mapping:info', description: 'List ORM entity mappings discovered by SymPress.')]
final class MappingInfoCommand extends Command
{
    public function __construct(
        private readonly EntityClassRegistry $entities,
        private readonly MetadataFactory $metadataFactory,
    ) {

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('manager', null, InputOption::VALUE_REQUIRED, 'Limit output to one entity manager.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manager = $this->optionalString($input->getOption('manager'));
        $rows = [];

        foreach ($this->groups($manager) as $group => $classes) {
            foreach ($classes as $className) {
                $metadata = $this->metadataFactory->getMetadataFor($className);
                $rows[] = [
                    $group,
                    $className,
                    $metadata->tableName,
                    implode(', ', $metadata->identifier),
                    (string) count($metadata->columns()),
                    (string) count($metadata->indexes),
                ];
            }
        }

        (new Table($output))
            ->setHeaders(['manager', 'class', 'table', 'identifier', 'columns', 'indexes'])
            ->setRows($rows)
            ->render();

        return Command::SUCCESS;
    }

    /** @return array<string, list<class-string>> */
    private function groups(?string $manager): array
    {
        if ($manager === null) {
            return $this->entities->groups();
        }

        return [$manager => $this->entities->classes($manager)];
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
