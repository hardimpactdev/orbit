<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\Nodes\LinuxUserName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use Throwable;

/** The home of a Process's non-root account on its Node, through the agent's `account.lookup`. */
final readonly class SshProcessUserResolver
{
    public function __construct(
        private AgentCommandRunner $agents,
    ) {}

    public function home(Node $node, string $user): string
    {
        if (! LinuxUserName::isValid($user) || $user === 'root') {
            throw new ResourceOperationException('process.user_invalid', 'Process user must be a valid non-root account name.');
        }

        $host = $node->wireguard_ip;
        if (! is_string($host) || $host === '') {
            $this->unavailable();
        }

        try {
            $account = $this->agents->run($node, 'account.lookup', ['user' => $user]);
        } catch (Throwable) {
            $this->unavailable();
        }

        $home = $account['home'] ?? null;
        if (
            ($account['user'] ?? null) !== $user
            || ! is_int($account['uid'] ?? null)
            || $account['uid'] === 0
            || ! is_string($home)
            || ! $this->validHome($home)
        ) {
            $this->unavailable();
        }

        return $home;
    }

    private function validHome(string $home): bool
    {
        return str_starts_with($home, '/')
            && strlen($home) <= 4096
            && preg_match('/[\x00-\x1F\x7F]/', $home) !== 1
            && array_all(
                array_slice(explode('/', $home), 1),
                static fn (string $segment): bool => ! in_array($segment, ['', '.', '..'], true),
            );
    }

    private function unavailable(): never
    {
        throw new ResourceOperationException('process.user_unavailable', 'The Process user account or its home is unavailable.');
    }
}
