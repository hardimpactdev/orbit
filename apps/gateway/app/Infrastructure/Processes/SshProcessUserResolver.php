<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\NodeFacts\NodeAgentLiveness;
use App\Domain\NodeFacts\NodeAgentState;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Nodes\LinuxUserName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeFact;

/** The home of a Process's non-root account, from its Node's current accounts fact. */
final readonly class SshProcessUserResolver
{
    public function __construct(
        private NodeAgentLiveness $liveness,
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

        $fact = NodeFact::query()->where('node_id', $node->id)->where('kind', NodeFactKind::Accounts)
            ->where('subject', '')->first();

        if ($fact === null || $this->liveness->for($node)?->state !== NodeAgentState::Online) {
            $this->unavailable();
        }

        $account = $this->observedAccount($fact, $user);
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
