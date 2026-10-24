<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Infrastructure\Ssh\SshConnection;
use App\Models\Node;
use InvalidArgumentException;

/** A Linux guest before enrollment, reached through its trusted host or its pinned SSH identity. */
final readonly class AgentGuest
{
    public string $platform;

    private function __construct(
        public string $name,
        public string $architecture,
        public ?Node $host,
        public ?string $project,
        public ?SshConnection $connection,
    ) {
        $this->platform = 'linux';
    }

    public static function incus(Node $host, string $project, string $name): self
    {
        foreach ([$project, $name] as $value) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9-]{0,62}\z/D', $value) !== 1) {
                throw new InvalidArgumentException('The Incus guest project or name is invalid.');
            }
        }

        return new self($name, (string) $host->architecture, $host, $project, null);
    }

    public static function ssh(string $name, string $architecture, SshConnection $connection): self
    {
        return new self($name, $architecture, null, null, $connection);
    }
}
