<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use Orbit\Sdk\Requests\Nodes\ConvergeNodeRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class ConvergeNodeCommand extends NodeCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'node:converge
        {node? : Positive numeric Node ID}
        {--base : Apply only Orbit-owned files, units, agent and CLI}
        {--roles= : Apply the base and these recorded roles (comma separated)}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Re-apply the Gateway-rendered Orbit footprint of a Node. It never changes an Instance or a role.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $nodeId = $this->positiveId('node', 'Node', 'node.id_invalid');
        if ($nodeId === null) {
            return self::FAILURE;
        }
        $roles = $this->option('roles');
        if (($this->option('base') === true && $roles !== null)
            || (is_string($roles) && ($roles === '' || array_diff(explode(',', $roles),
                ['gateway', 'vpn', 'router', 'ingress', 'app-dev', 'app-prod', 'metrics', 'database', 'websocket', 'analytics', 'ci-runner', 'swamp']) !== []))) {
            return $this->renderGatewayFailure('validation.failed', 'Select either --base or a nonempty list of known roles.');
        }
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $result = $this->sendWithProgress(
            $connector,
            new ConvergeNodeRequest($nodeId, $this->option('base') === true,
                is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null,
                is_string($roles) ? explode(',', $roles) : null),
            OperationResponse::class,
            ['Converge Node footprint', 'Converging Node footprint', 'Node footprint request completed'],
            static fn (OperationResponse $response): ProgressOutcome => new ProgressOutcome(ProgressState::Success,
                'Node footprint Operation recorded'),
        );

        if (! $result instanceof OperationResponse) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);

        return $profile === null ? self::FAILURE
            : $this->followOperation($connector, $profile, $result, 'Converge Node footprint', 'Node footprint');
    }
}
