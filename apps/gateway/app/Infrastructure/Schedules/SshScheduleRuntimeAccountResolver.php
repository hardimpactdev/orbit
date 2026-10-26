<?php

declare(strict_types=1);

namespace App\Infrastructure\Schedules;

use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Schedules\ScheduleRuntimeAccount;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use Throwable;

final readonly class SshScheduleRuntimeAccountResolver implements ScheduleRuntimeAccountResolver
{
    public function __construct(
        private AgentCommandRunner $agents,
    ) {}

    public function resolve(Node $node, string $user): ScheduleRuntimeAccount
    {
        $host = $node->wireguard_ip;

        if (! is_string($host) || $host === '') {
            $this->unreachable();
        }

        try {
            $account = $this->agents->run($node, 'account.lookup', ['user' => $user]);
        } catch (AgentCommandRefused) {
            $this->unavailable();
        } catch (Throwable) {
            $this->unreachable();
        }

        $name = $account['user'] ?? null;
        $group = $account['group'] ?? null;

        if (
            $name !== $user
            || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $user) !== 1
            || ! is_string($group)
            || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $group) !== 1
            || ! is_string($account['home'] ?? null)
            || ! is_string($account['shell'] ?? null)
        ) {
            $this->unavailable();
        }

        return new ScheduleRuntimeAccount($user, $group, $account['home'], $account['shell']);
    }

    private function unreachable(): never
    {
        throw new ScheduleOperationException(
            'resolve-account',
            ScheduleErrorCode::NodeUnreachable,
            'The Schedule host Node is unreachable.',
        );
    }

    private function unavailable(): never
    {
        throw new ScheduleOperationException(
            'resolve-account',
            ScheduleErrorCode::TargetUnavailable,
            'The Schedule runtime account is unavailable.',
        );
    }
}
