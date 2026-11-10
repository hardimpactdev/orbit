<?php

declare(strict_types=1);

namespace App\Infrastructure\Schedules;

use App\Domain\NodeFacts\NodeAgentLiveness;
use App\Domain\NodeFacts\NodeAgentState;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Schedules\ScheduleRuntimeAccount;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Models\Node;
use App\Models\NodeFact;

final readonly class SshScheduleRuntimeAccountResolver implements ScheduleRuntimeAccountResolver
{
    public function __construct(
        private NodeAgentLiveness $liveness,
    ) {}

    public function resolve(Node $node, string $user): ScheduleRuntimeAccount
    {
        $host = $node->wireguard_ip;

        if (! is_string($host) || $host === '') {
            $this->unreachable();
        }

        $fact = NodeFact::query()->where('node_id', $node->id)->where('kind', NodeFactKind::Accounts)
            ->where('subject', '')->first();

        if ($fact === null || $this->liveness->for($node)?->state !== NodeAgentState::Online) {
            $this->unavailable();
        }

        $account = $this->observedAccount($fact, $user);
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

    /** @return array<string, mixed> */
    private function observedAccount(NodeFact $fact, string $user): array
    {
        $accounts = $fact->data['accounts'] ?? null;
        foreach (is_array($accounts) ? $accounts : [] as $account) {
            if (is_array($account) && ($account['user'] ?? null) === $user) {
                return $account;
            }
        }

        $this->unavailable();
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
