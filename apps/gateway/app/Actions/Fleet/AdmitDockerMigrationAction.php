<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Fleet\DockerMigrationIntent;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Models\Node;
use App\Models\Operation;

final readonly class AdmitDockerMigrationAction
{
    public function __construct(private DockerMigrationIntent $intent, private StartOperationAction $start) {}

    public function execute(Node $node, MachineRecipe $recipe, string $mode, bool $fallback = false, ?string $triggeredBy = null, ?string $requestId = null): Operation
    {
        if (! in_array($mode, ['plan', 'migrate', 'restore-policies'], true) || ($mode === 'restore-policies' && $fallback)) {
            throw new ResourceOperationException('node.docker_migration_invalid', 'Policy recovery cannot use migration fallback.', 422);
        }

        return $this->start->execute('fleet.docker_migrate', 'node', $node->id, $node->id,
            $this->intent->input($node, $recipe, $mode, $fallback), $triggeredBy, $requestId, lockKey: 'docker_migrate:node:'.$node->id);
    }
}
