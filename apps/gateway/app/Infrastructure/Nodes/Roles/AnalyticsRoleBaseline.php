<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Actions\Analytics\StartAnalyticsOperationAction;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Operations\AwaitOperation;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class AnalyticsRoleBaseline implements RoleBaseline
{
    public function __construct(private StartAnalyticsOperationAction $operations, private AwaitOperation $await) {}
    public function converge(Node $node, NodeRole $assignment): void
    {
        $this->await->execute($this->operations->execute($node, $assignment));
    }
    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->await->execute($this->operations->execute($node, $assignment, 'disable'));
    }
    public function removeUnreachable(Node $node, NodeRole $assignment): void
    {
        $this->await->execute($this->operations->execute($node, $assignment, 'disable', unreachable: true));
    }
}
