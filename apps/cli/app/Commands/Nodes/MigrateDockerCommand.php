<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use Orbit\Sdk\Requests\Fleet\MigrateDockerRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class MigrateDockerCommand extends NodeCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'node:migrate-docker
        {node : Node ID or name}
        {--dry-run : Read the migration plan without writes}
        {--yes : Confirm the maintenance action}
        {--fallback : Authorize a fresh engine if metadata is unreadable}
        {--restore-policies : Recover policies after manual CE restore}
        {--recipe= : Require this exact serving recipe}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Migrate one Docker CE Node to Ubuntu docker.io in a native Swamp run.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        if ($this->option('restore-policies') && ($this->option('dry-run') || $this->option('fallback'))) {
            return $this->renderGatewayFailure('node.docker_migration_invalid', '--restore-policies cannot be combined with --dry-run or --fallback.');
        }
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->resolveNodeId($connector, $this->argument('node'));

        if ($nodeId === null) {
            return self::FAILURE;
        }

        if (! $this->option('dry-run') && ! $this->confirmAction(
            "Migrate Docker or restore policies on Node #{$nodeId}? Containers may stop.",
            'Docker maintenance cancelled.', requiredCode: 'node.confirmation_required',
            requiredMessage: 'Review --dry-run first. --yes is required for Docker migration or policy recovery.',
        )) {
            return self::FAILURE;
        }

        $result = $this->sendWithProgress(
            $connector,
            new MigrateDockerRequest($nodeId, $this->option('dry-run') ? 'plan' : ($this->option('restore-policies') ? 'restore-policies' : 'migrate'),
                ! $this->option('dry-run'), $this->option('fallback') === true,
                is_string($this->option('recipe')) ? $this->option('recipe') : null,
                is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null),
            OperationResponse::class,
            ['Migrate Docker', 'Migrating Docker', 'Docker migration request completed'],
            static fn (OperationResponse $response): ProgressOutcome => new ProgressOutcome(ProgressState::Success,
                'Docker migration Operation recorded'),
        );

        if (! $result instanceof OperationResponse) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);

        return $profile === null ? self::FAILURE
            : $this->followOperation($connector, $profile, $result, 'Migrate Docker', 'Docker migration');
    }
}
