<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Actions\Metrics\StartMetricsOperationAction;
use App\Domain\Metrics\MetricsPublicationCleanup;
use App\Domain\Metrics\MetricsPublicationReport;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Operations\AwaitOperation;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class MetricsRoleBaseline implements RoleBaseline
{
    public function __construct(private StartMetricsOperationAction $operations, private AwaitOperation $await, private MetricsPublicationReport $report) {}
    public function converge(Node $node, NodeRole $assignment): void
    {
        $this->await->execute($this->operations->execute($node, $assignment, 'enable'));
    }
    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $operation = $this->operations->execute($node, $assignment, 'disable', $purgeData);
        $this->await->execute($operation);
        $this->report->record(MetricsPublicationCleanup::from($operation->input['publication_cleanup']));
    }
    public function removeUnreachable(Node $node, NodeRole $assignment): void
    {
        $operation = $this->operations->execute($node, $assignment, 'disable', unreachable: true);
        $this->await->execute($operation);
        $this->report->record(MetricsPublicationCleanup::from($operation->input['publication_cleanup']));
    }
}
