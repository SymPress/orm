<?php

declare(strict_types=1);

namespace SymPress\Orm\Dbal;

final class ConnectionProvider
{
    public function __construct(private ?ConnectionInterface $connection = null)
    {
    }

    public static function fromDatabase(ConnectionInterface|\wpdb|null $database): self
    {
        if ($database instanceof ConnectionInterface) {
            return new self($database);
        }

        if ($database instanceof \wpdb) {
            return new self(new WpdbConnection($database));
        }

        return new self();
    }

    public function connection(): ConnectionInterface
    {
        return $this->connection ??= new WpdbConnection();
    }
}
