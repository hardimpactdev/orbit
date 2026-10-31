<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Orbit\Extensions\AiRouter\Domain\AiRouterPlacement;
use Orbit\Extensions\AiRouter\Domain\AiRouterSnapshotStore;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

final readonly class UpdateAiRouterAccountAction
{
    public function __construct(private AiRouterState $state, private AiRouterSnapshotStore $snapshots,
        private AiRouterPlacement $placement, private StartOperationAction $operations, private OperationIdempotency $idempotency) {}

    public function execute(string $account, bool $disabled, ?string $requestId = null): Operation
    {
        if (($replay = $this->idempotency->replay('ai_router.account_update')) !== null) {
            return $replay;
        }
        $this->state->assertEnabled();
        $node = $this->placement->node($this->state->nodeId() ?? 0);
        if (! preg_match('/^[A-Za-z0-9._-]{1,255}$/D', $account)
            || ! array_any($this->snapshots->accounts(), static fn ($row): bool => $row->id === $account)) {
            throw new ResourceOperationException('resource.not_found', 'The account was not found in the snapshot.', 404);
        }
        $origin = rtrim((string) $this->state->routerUrl(), '/');
        $url = parse_url($origin);
        if (! is_array($url) || ! in_array($url['scheme'] ?? null, ['http', 'https'], true)
            || ! isset($url['host']) || isset($url['pass']) || isset($url['user']) || isset($url['query']) || isset($url['fragment'])
            || ! in_array($url['path'] ?? '', ['', '/'], true)) {
            throw new ResourceOperationException('ai_router.url_invalid', 'Account updates require the configured CLIProxyAPI origin.', 422);
        }

        return $this->operations->execute('ai_router.account_update', 'ai_router', $node->id, $node->id,
            ['origin' => $origin, 'account' => $account, 'disabled' => $disabled,
                'router' => ['$secret' => 'ai_router.management', 'id' => $node->id]], 'operator', $requestId);
    }
}
