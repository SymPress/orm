<?php

declare(strict_types=1);

$autoloaders = [
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];

foreach ($autoloaders as $autoloader) {
    if (!is_readable($autoloader)) {
        continue;
    }

    require_once $autoloader;
    break;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'SymPress\\Orm\\Tests\\' => __DIR__ . '/',
        'SymPress\\Orm\\' => __DIR__ . '/../src/',
    ];

    foreach ($prefixes as $prefix => $baseDirectory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $path = $baseDirectory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_readable($path)) {
            require $path;
        }
    }
});

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';
        public int|string $insert_id = 0;

        /** @var list<array{table: string, data: array<string, mixed>}> */
        public array $inserted = [];

        /** @var list<array{table: string, data: array<string, mixed>, where: array<string, mixed>}> */
        public array $updated = [];

        /** @var list<array{table: string, where: array<string, mixed>}> */
        public array $deleted = [];

        /** @var list<string> */
        public array $queries = [];

        /** @var list<array<string, mixed>> */
        public array $resultRows = [];

        public int $countResult = 0;

        public bool|int $updateResult = true;

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        public function prepare(string $query, mixed ...$args): string
        {
            return vsprintf(str_replace(['%d', '%f'], '%s', $query), array_map(
                static fn (mixed $value): string => "'" . str_replace("'", "''", (string) $value) . "'",
                $args,
            ));
        }

        public function get_var(string $query): int
        {
            return $this->countResult;
        }

        public function get_results(string $query, string|int $output = ARRAY_A): array
        {
            return $this->resultRows;
        }

        public function insert(string $table, array $data): bool|int
        {
            $this->inserted[] = [
                'table' => $table,
                'data' => $data,
            ];
            $this->insert_id = is_int($this->insert_id) ? $this->insert_id + 1 : 1;

            return 1;
        }

        public function update(string $table, array $data, array $where): bool|int
        {
            $this->updated[] = [
                'table' => $table,
                'data' => $data,
                'where' => $where,
            ];

            return $this->updateResult;
        }

        public function delete(string $table, array $where): bool|int
        {
            $this->deleted[] = [
                'table' => $table,
                'where' => $where,
            ];

            return 1;
        }

        public function query(string $query): bool|int
        {
            $this->queries[] = $query;

            return 1;
        }
    }
}
