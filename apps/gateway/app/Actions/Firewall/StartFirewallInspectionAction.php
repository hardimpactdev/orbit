<?php

declare(strict_types=1);

namespace App\Actions\Firewall;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Firewall\FirewallInspectionInput;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

/** Records a native read; the request never inspects the Node. */
final readonly class StartFirewallInspectionAction
{
    public function __construct(private StartOperationAction $operations) {}

    public function execute(Node $node, ?Node $caller = null, ?string $requestId = null): Operation
    {
        if ($node->platform !== 'linux') {
            throw new ResourceOperationException('firewall.platform_unsupported', 'Firewall inspection requires a Linux Node.', 422);
        }
        app(DirectOperation::class)->writer('firewall.inspect');

        return $this->operations->execute('firewall.inspect', 'node_firewall', $node->id, $node->id,
            ['node_id' => $node->id, 'caller_node_id' => $caller?->id, 'intent' => app(FirewallInspectionInput::class)->capture($node)], 'operator', $requestId);
    }
}
