<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use App\Support\GatewayFailureRenderer;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\DatabaseServers\ListDatabaseServersRequest;
use Orbit\Sdk\Requests\Nodes\AddNodeRoleRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Orbit\Sdk\Responses\DatabaseServers\DatabaseServersResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Responses\Processes\ProcessesResponse;

class AddNodeRoleCommand extends NodeCommand
{
    use FollowsNodeRoleOperations;

    #[\Override]
    protected $signature = 'node:role:add
        {node? : Positive numeric Node ID}
        {role? : Role name}
        {--converge : Converge an existing assignment}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--postgres-process= : For the analytics role: ID of its PostgreSQL 16 or managed PostgreSQL 18 storage Process}
        {--clickhouse-process= : For the analytics role: ID of its ClickHouse Process on a database Node}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Add one role assignment to a node.';

    /** @param array<string, mixed> $details */
    #[\Override]
    protected function renderGatewayFailure(string $code, string $message, ?string $requestId = null, ?string $humanMessage = null, array $details = []): int
    {
        GatewayFailureRenderer::write($this, $code, $message, $requestId, $humanMessage, $details, includeCode: true);

        return self::FAILURE;
    }

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $nodeId = $this->argument('node') === null ? null : $this->positiveId('node', 'Node', 'node.id_invalid');

        if ($this->argument('node') !== null && $nodeId === null) {
            return self::FAILURE;
        }

        $role = $this->argument('role');

        if ($role !== null || ! $this->consoleMode()->mayPrompt) {
            $role = $this->stringArgument('role', 'Role', 'node_role.role_required');

            if ($role === null) {
                return self::FAILURE;
            }
        }

        if ($nodeId === null && ! $this->consoleMode()->mayPrompt) {
            return $this->renderGatewayFailure('node.id_invalid', 'Node ID must be a positive integer.');
        }

        if ($role === null) {
            $roles = ['gateway', 'ingress', 'app-dev', 'app-prod', 'database', 'analytics', 'metrics', 'websocket', 'ci-runner', 'swamp'];
            $role = (string) $this->commandPrompts()->selectEntity('Role', ['Name'], array_combine(
                $roles, array_map(static fn (string $name): array => [$name], $roles),
            ));
        }

        foreach (['postgres-process', 'clickhouse-process'] as $option) {
            if ($role !== 'analytics' && $this->option($option) !== null) {
                $this->renderGatewayFailure(
                    'validation.failed',
                    'Analytics storage options are only valid for the analytics role.',
                    details: ['field' => '--'.$option],
                );

                return self::FAILURE;
            }
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        if ($nodeId === null) {
            $nodes = $this->sendWithProgress($connector, new ListNodesRequest, NodesResponse::class,
                ['List Nodes', 'Loading Nodes', 'Loaded Nodes']);

            if (! $nodes instanceof NodesResponse) {
                return self::FAILURE;
            }

            $rows = [];
            foreach ($nodes->nodes as $node) {
                $rows[$node->id] = [(string) $node->id, $node->name];
            }
            $nodeId = $this->commandPrompts()->selectEntity('Node', ['ID', 'Name'], $rows);
            if (! is_int($nodeId)) {
                return self::FAILURE;
            }
        }

        $storage = $role === 'analytics' ? $this->analyticsStorage($connector) : [null, null];

        if ($storage === null) {
            return self::FAILURE;
        }

        $request = new AddNodeRoleRequest($nodeId, $role, $this->option('converge') === true, $storage[0], $storage[1],
            is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null);
        $response = $this->sendWithProgress(
            $connector,
            $request,
            OperationResponse::class,
            ['Add Node role', 'Adding Node role', 'Node role request recorded'],
            static fn (object $response): ProgressOutcome => new ProgressOutcome(ProgressState::Success, 'Node role Operation recorded'),
        );

        if (! $response instanceof OperationResponse) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);

        return $profile === null ? self::FAILURE
            : $this->followOperation($connector, $profile, $response, 'Add Node role', 'Node role');
    }

    /**
     * The analytics role names its two storage Processes. An omitted one is picked from the Docker
     * Processes that run the supported image (PostgreSQL 18 also needs managed ownership); `--json` and `--no-interaction` refuse instead.
     *
     * @return array{int, int}|null
     */
    private function analyticsStorage(GatewayConnector $connector): ?array
    {
        $ids = [];
        $candidates = null;
        $managedPostgres = null;

        foreach ([
            ['postgres-process', 'PostgreSQL Process', '/(?:^|\/)postgres:16(?:[.-][A-Za-z0-9._-]*)?(?:@|$)/'],
            ['clickhouse-process', 'ClickHouse Process', '/(?:^|\/)clickhouse\/clickhouse-server(?::|@|$)/'],
        ] as [$option, $label, $image]) {
            $value = $this->option($option);

            $id = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

            if (is_int($id)) {
                $ids[] = $id;

                continue;
            }

            if ($value !== null || ! $this->consoleMode()->mayPrompt) {
                $this->renderGatewayFailure(
                    'node_role.storage_process_required',
                    "--{$option} takes the numeric ID of a {$label}.",
                    details: ['field' => $option],
                );

                return null;
            }

            $candidates ??= $this->sendWithProgress(
                $connector,
                new ListProcessesRequest,
                ProcessesResponse::class,
                ['List Processes', 'Loading Processes', 'Loaded Processes'],
            );

            if (! $candidates instanceof ProcessesResponse) {
                return null;
            }

            if ($option === 'postgres-process') {
                $servers = $this->sendWithProgress($connector, new ListDatabaseServersRequest, DatabaseServersResponse::class,
                    ['List Database Servers', 'Loading Database Servers', 'Loaded Database Servers']);
                if (! $servers instanceof DatabaseServersResponse) {
                    return null;
                }
                $managedPostgres = [];
                foreach ($servers->servers as $server) {
                    if ($server->driver === 'pgsql' && $server->processId !== null) {
                        $managedPostgres[$server->processId] = true;
                    }
                }
            }

            $rows = [];

            foreach ($candidates->processes as $process) {
                $declared = $process->runtimeConfig['image'] ?? null;

                $pattern = $option === 'postgres-process' && isset($managedPostgres[$process->id])
                    ? '/(?:^|\/)postgres:18(?:[.-][A-Za-z0-9._-]*)?(?:@|$)/' : $image;

                if ($process->targetType === 'node' && $process->runtime === 'docker' && is_string($declared) && preg_match($pattern, $declared) === 1) {
                    $rows[$process->id] = [(string) $process->id, $process->name, $declared];
                }
            }

            if ($rows === []) {
                $this->renderGatewayFailure(
                    'node_role.storage_process_required',
                    "No {$label} exists yet. Create one on a database Node with process:create first.",
                    details: ['field' => $option],
                );

                return null;
            }

            $selected = $this->commandPrompts()->selectEntity($label, ['ID', 'Name', 'Image'], $rows);

            if (! is_int($selected)) {
                return null;
            }

            $ids[] = $selected;
        }

        return [$ids[0], $ids[1]];
    }
}
