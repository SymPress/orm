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

        $this->schemaTool->refreshSchemaState();
        if (!$input->getOption('destructive') && $this->schemaTool->requiresDestructiveUpdates($manager, false)) {
            $io->error('Schema changes require explicit --destructive intent or a reviewed inverse migration.');

            return Command::FAILURE;
        }

        $up = $this->schemaTool->getUpdateSchemaSql($manager, (bool) $input->getOption('destructive'));

        if ($up === []) {
            $io->warning('No entity metadata found.');

            return Command::SUCCESS;
        }

        $namespace = trim((string) $input->getOption('namespace'), '\\');

        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*$/D', $namespace) !== 1) {
            $io->error('Invalid migration namespace.');

            return Command::INVALID;
        }
        try {
            // @phpstan-ignore function.resultUnused (TOKEN_PARSE validates syntax by throwing ParseError.)
            token_get_all('<?php namespace ' . $namespace . ';', TOKEN_PARSE);
        } catch (\ParseError) {
            $io->error('Invalid migration namespace.');

            return Command::INVALID;
        }

        $className = 'Version' . gmdate('YmdHis');
        $file = rtrim($path, '/') . '/' . $className . '.php';

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            $io->error(sprintf('Could not create migration directory "%s".', $path));

            return Command::FAILURE;
        }

        file_put_contents($file, $this->migrationClass($namespace, $className, $up));
        $io->success(sprintf('Generated migration "%s".', $file));

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $up
     */
    private function migrationClass(string $namespace, string $className, array $up): string
    {
        return sprintf(
            "<?php\n\n%s\n\nnamespace %s;\n\nuse SymPress\\WordPress\\Migration\\Domain\\AbstractMigration;\n\nfinal class %s extends AbstractMigration\n{\n    protected const string VERSION = '%s';\n\n    /** @return list<string> */\n    public function up(): array\n    {\n        \$prefix = \$this->database->prefix;\n        if (preg_match('/^[A-Za-z0-9_]*$/D', \$prefix) !== 1) {\n            throw new \\InvalidArgumentException('WordPress table prefix must contain only SQL identifier characters.');\n        }\n        return %s;\n    }\n\n    /** @return list<string> */\n    public function down(): array\n    {\n        throw new \RuntimeException('Generated ORM schema migrations are irreversible; supply an explicit reviewed inverse migration.');\n    }\n}\n",
            'declare(strict_types=1);',
            $namespace,
            $className,
            gmdate('Y.m.d.His'),
            $this->exportList($up, 2),
        );
    }

    /** @param list<string> $statements */
    private function exportList(array $statements, int $indent): string
    {
        $padding = str_repeat(' ', $indent * 4);
        $innerPadding = str_repeat(' ', ($indent + 1) * 4);
        $lines = ['['];

        foreach ($statements as $statement) {
            // Rebind only the generated leading table identifier; SQL values and column names remain literal.
            $prefix = $this->schemaTool->getTablePrefix();
            if (preg_match('/^(CREATE TABLE|ALTER TABLE|DROP TABLE(?: IF EXISTS)?) ([A-Za-z0-9_]+)(.*)$/sD', $statement, $match) !== 1 || !str_starts_with($match[2], $prefix)) {
                throw new \RuntimeException('Generated schema SQL cannot be rebound to a portable table identifier.');
            }
            $expression = var_export($match[1] . ' ', true) . ' . $prefix . ' . var_export(substr($match[2], strlen($prefix)) . $match[3], true);
            $lines[] = sprintf('%s%s,', $innerPadding, $expression);
        }

        $lines[] = $padding . ']';

        return implode("\n", $lines);
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
