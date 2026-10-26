<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\LinuxUserName;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeLockLoss;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use RuntimeException;
use Throwable;

/** The Node's managed account, through the agent's `account.lookup`. */
final readonly class AgentManagedUserAccountResolver implements ManagedUserAccountResolver
{
    public function __construct(
        private AgentCommandRunner $agents,
    ) {}

    public function resolve(Node $node): ManagedUserAccount
    {
        try {
            $account = $this->agents->run($node, 'account.lookup', ['user' => $node->user]);
            $user = $account['user'] ?? null;
            $group = $account['group'] ?? null;
            $home = $account['home'] ?? null;

            if ($user !== $node->user || $user === '' || ! is_string($group) || ! LinuxUserName::isValid($group) || ! is_string($home) || ! $this->validHome($home)) {
                throw new RuntimeException;
            }

            return new ManagedUserAccount($user, $group, $home);
        } catch (Throwable $exception) {
            throw new NodeProvisioningException(
                'managed-user',
                'node.managed_user_unavailable',
                'The managed user account is unavailable.',
                NodeLockLoss::keep($exception),
            );
        }
    }

    private function validHome(string $home): bool
    {
        if ($home === '' || $home[0] !== '/' || str_contains($home, "\n") || str_contains($home, "\r")) {
            return false;
        }

        return array_all(
            array_slice(explode('/', $home), offset: 1),
            static fn ($segment) => ! ($segment === '' || $segment === '.' || $segment === '..'),
        );
    }
}
