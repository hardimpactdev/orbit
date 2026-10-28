<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\Operation;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeRole;

final readonly class UpdateAnalyticsAction
{
    public function __construct(
        private AnalyticsRoleSettingsRepository $settings,
        private StartAnalyticsOperationAction $operations,
    ) {}

    /**
     * Pins another Plausible version and converges the role again, which replaces the `plausible`
     * Process. A failed convergence puts the earlier version back, so the next converge does not
     * retry a version that does not start.
     *
     * @return array{node_id: int, node_name: string, version: string, previous_version: string}
     */
    public function execute(string $version): Operation
    {
        $assignment = NodeRole::query()
            ->where('role', RoleName::Analytics->value)
            ->where('status', LifecycleStatus::Active->value)
            ->with('node')
            ->first();

        if (! $assignment instanceof NodeRole) {
            throw new ResourceOperationException(
                errorCode: 'analytics.role_missing',
                message: 'No Node has an active analytics role.',
                status: 409,
            );
        }

        $node = $assignment->node;
        $previous = $this->settings->version($node);

        $this->settings->storeVersion($node, $version);
        try {
            return $this->operations->execute($node, $assignment, 'update', previousVersion: $previous);
        } catch (\Throwable $exception) {
            $this->settings->storeVersion($node, $previous);
            throw $exception;
        }
    }
}
