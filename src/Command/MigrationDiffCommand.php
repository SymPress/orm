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
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'orm:migrations:diff', description: 'Generate a SymPress migration from ORM entity metadata.')]
final class MigrationDiffCommand extends Command
{
    public function __construct(private readonly SchemaTool $schemaTool)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('manager', InputArgument::OPTIONAL, 'Entity manager/plugin slug to generate for.')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Migration class namespace.', 'App\\Migration')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Directory where the migration class should be written.')
            ->addOption('destructive', null, InputOption::VALUE_NONE, 'Include DROP COLUMN and DROP INDEX statements in the generated migration.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $manager = $this->optionalString($input->getArgument('manager'));
        $path = $this->optionalString($input->getOption('path'));

        if ($path === null) {
            $io->error('The --path option is required.');

            return Command::INVALID;
        }

        if (!class_exists('SymPress\\WordPress\\Migration\\Domain\\AbstractMigration')) {
            $io->error('sympress/migration is not installed; cannot generate an AbstractMigration class.');

            return Command::FAILURE;
        }

        $up = $this->schemaTool->getUpdateSchemaSql($manager, (bool) $input->getOption('destructive'));

        if ($up === []) {
            $io->warning('No entity metadata found.');

            return Command::SUCCESS;
        }

        $namespace = trim((string) $input->getOption('namespace'), '\\');
        $className = 'Version' . gmdate('YmdHis');
        $file = rtrim($path, '/') . '/' . $className . '.php';

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            $io->error(sprintf('Could not create migration directory "%s".', $path));

            return Command::FAILURE;
        }

        file_put_contents($file, $this->migrationClass($namespace, $className, $up, $this->schemaTool->getDropSchemaSql($manager)));
        $io->success(sprintf('Generated migration "%s".', $file));

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $up
     * @param list<string> $down
     */
    private function migrationClass(string $namespace, string $className, array $up, array $down): string
    {
        return sprintf(
            "<?php\n\n%s\n\nnamespace %s;\n\nuse SymPress\\WordPress\\Migration\\Domain\\AbstractMigration;\n\nfinal class %s extends AbstractMigration\n{\n    protected const string VERSION = '%s';\n\n    /** @return list<string> */\n    public function up(): array\n    {\n        return %s;\n    }\n\n    /** @return list<string> */\n    public function down(): array\n    {\n        return %s;\n    }\n}\n",
            'declare(strict_types=1);',
            $namespace,
            $className,
            gmdate('Y.m.d.His'),
            $this->exportList($up, 2),
            $this->exportList($down, 2),
        );
    }

    /** @param list<string> $statements */
    private function exportList(array $statements, int $indent): string
    {
        $padding = str_repeat(' ', $indent * 4);
        $innerPadding = str_repeat(' ', ($indent + 1) * 4);
        $lines = ['['];

        foreach ($statements as $statement) {
            $lines[] = sprintf('%s%s,', $innerPadding, var_export($statement, true));
        }

        $lines[] = $padding . ']';

        return implode("\n", $lines);
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
