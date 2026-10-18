<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use App\Domain\Shared\ResourceOperationException;
use Orbit\Extensions\AiRouter\Domain\AiRouterAccountControlClient;
use Orbit\Extensions\AiRouter\Domain\AiRouterPlacement;
use Orbit\Extensions\AiRouter\Domain\AiRouterPoolCompiler;
use Orbit\Extensions\AiRouter\Domain\AiRouterSnapshotStore;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

final readonly class UpdateAiRouterAccountAction
{
    public function __construct(
        private AiRouterState $state,
        private AiRouterSnapshotStore $snapshots,
        private AiRouterPlacement $placement,
        private AiRouterAccountControlClient $client,
        private AiRouterPoolCompiler $compiler = new AiRouterPoolCompiler,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(string $account, bool $disabled): array
    {
        $this->state->assertEnabled();
        $node = $this->placement->node($this->state->nodeId() ?? 0);

        $accounts = $this->snapshots->accounts();
        $known = array_any($accounts, static fn ($row): bool => $row->id === $account);

        if (! $known) {
            throw new ResourceOperationException(
                'resource.not_found',
                "Account [{$account}] was not found in the snapshot.",
                404,
            );
        }

        $wireguardIp = $node->wireguard_ip;
        if (! is_string($wireguardIp) || $wireguardIp === '') {
            throw new ResourceOperationException(
                'ai_router.node_invalid',
                'ai-router enable requires an active Linux Node with a WireGuard address.',
                422,
            );
        }

        $this->client->setDisabled(
            $wireguardIp,
            443,
            (string) $this->state->controlToken(),
            $account,
            $disabled,
        );
        $updated = $this->compiler->withDisabled($accounts, $account, $disabled);
        $snapshot = $this->snapshots->write($updated, date(DATE_ATOM));
        $match = array_first(array_filter(
            $snapshot->accounts,
            static fn ($row): bool => $row->id === $account,
        )) ?? null;

        return $match?->toArray() ?? ['id' => $account, 'disabled' => $disabled];
    }
}
