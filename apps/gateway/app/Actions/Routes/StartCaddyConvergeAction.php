<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

final readonly class StartCaddyConvergeAction
{
    public function __construct(private StartOperationAction $operations) {}

    public function execute(Node $node, string $reason, ?string $requestId = null): Operation
    {
        if ($reason === '' || strlen($reason) > 200) {
            throw new ResourceOperationException('caddy.reason_invalid', 'A Caddy convergence reason is required and is at most 200 bytes.', 422);
        }

        return $this->operations->execute('route.caddy_converge', 'node_caddy', $node->id, $node->id,
            ['reason' => $reason], 'operator', $requestId);
    }
}
