<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use App\Support\GatewayFailureRenderer;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\ListNodeRolesRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRoleRequest;
use Orbit\Sdk\Responses\Nodes\NodeRolesResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;
use Orbit\Sdk\Responses\Nodes\RemovedNodeRoleResponse;

final class RemoveNodeRoleCommand extends NodeCommand
{
    use FollowsNodeRoleOperations;

    #[\Override]
    protected $signature = 'node:role:remove
        {node? : Positive numeric Node ID}
        {role? : Role name}
        {--force : Confirm role removal}
        {--purge-data : Request supported role-owned data cleanup}
        {--offline : Remove the role from a node Orbit cannot reach}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove one role assignment from a node.';

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
        if ($this->option('force') !== true && ($this->option('purge-data') === true || $this->option('offline') === true)) {
            return $this->renderGatewayFailure('validation.failed', '--purge-data and --offline require --force.');
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
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        if ($nodeId === null) {
            $nodes = $this->sendWithProgress($connector, new ListNodesRequest, NodesResponse::class, ['List Nodes', 'Loading Nodes', 'Loaded Nodes']);
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
        if ($role === null) {
            $roles = $this->sendWithProgress($connector, new ListNodeRolesRequest($nodeId), NodeRolesResponse::class, ['List roles', 'Loading roles', 'Loaded roles']);
            if (! $roles instanceof NodeRolesResponse) {
                return self::FAILURE;
            }
            $rows = [];
            foreach ($roles->assignments as $assignment) {
                if (! in_array($assignment->role, ['vpn', 'router', 'swamp'], true)) {
                    $rows[$assignment->role] = [$assignment->role, $assignment->status];
                }
            }
            if ($rows === []) {
                return $this->renderGatewayFailure('http.404', 'No removable role is assigned.');
            }
            $role = (string) $this->commandPrompts()->selectEntity('Role', ['Name', 'Status'], $rows);
        }

        if ($this->option('force') !== true) {
            $preview = $this->previewRemoval($connector, $nodeId, $role);

            if ($preview !== null) {
                return $preview;
            }
        }

        $response = $this->sendWithProgress(
            $connector,
            new RemoveNodeRoleRequest(
                nodeId: $nodeId,
                role: $role,
                force: true,
                purgeData: $this->option('purge-data') === true,
                offline: $this->option('offline') === true,
                idempotencyKey: is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null,
            ),
            RemovedNodeRoleResponse::class,
            ['Remove Node role', 'Recording Node role removal', 'Node role removal recorded'],
            static fn (object $response): ProgressOutcome => new ProgressOutcome(ProgressState::Success, 'Node role removal recorded'),
        );

        if (! $response instanceof RemovedNodeRoleResponse) {
            return self::FAILURE;
        }

        if ($response->operation === null) {
            if ($this->option('json') === true) {
                $this->writeJson($response->toArray());
            } else {
                $this->writeHumanMessage('Skipped — role not assigned (node_role.absent)');
            }

            return self::SUCCESS;
        }
        $profile = $this->activeGatewayProfile($repository);

        return $profile === null ? self::FAILURE : $this->followOperation($connector, $profile, $response->operation, 'Remove Node role', 'Node role removal');
    }

    private function previewRemoval(
        GatewayConnector $connector,
        int $nodeId,
        string $role,
    ): ?int {
        $progress = $this->progressDisplay('Review Node role removal');
        $progress->admit('preview', 'Review removal', 'Reviewing removal', 'Reviewed removal');

        try {
            $exception = $progress->during('preview', function () use ($connector, $nodeId, $role): GatewayApiException {
                try {
                    $this->sendOrThrow($connector, new RemoveNodeRoleRequest(
                        nodeId: $nodeId, role: $role, force: false, purgeData: false, offline: false,
                    ), RemovedNodeRoleResponse::class);
                } catch (GatewayApiException $exception) {
                    if ($this->isConsentPreview($exception)) {
                        return $exception;
                    }

                    throw $exception;
                }

                throw new GatewayApiException('Gateway response is invalid.', 'gateway.invalid_response');
            });
        } catch (GatewayApiException $exception) {
            return $this->renderPreviewFailure($exception);
        }

        $progress->complete('preview', ProgressState::Success);
        $progress->finish('Removal requires consent.');

        if (! $this->consoleMode()->mayPrompt) {
            return $this->renderApiFailure($exception);
        }

        $dependents = $this->dependents($exception);

        if ($dependents !== []) {
            ConsoleWriter::write($this->output, $this->humanRenderer()->properties([
                ['title' => 'Dependent resources:', 'items' => array_map(
                    static fn (string $dependent): array => ['label' => $dependent, 'fields' => []], $dependents)],
            ]));
        }

        $effect = $this->option('purge-data') === true ? ' and purge supported role-owned data' : '';

        if (! $this->confirmAction("Remove role '{$role}' from node #{$nodeId}{$effect}?",
            'Node role removal cancelled.', option: 'force')) {
            return self::FAILURE;
        }

        return null;
    }

    private function renderPreviewFailure(GatewayApiException $exception): int
    {
        return $this->renderApiFailure($exception);
    }

    private function isConsentPreview(GatewayApiException $exception): bool
    {
        $details = $exception->details();

        return
            $exception->errorCode() === 'validation.failed'
            && ($details['field'] ?? null) === 'force'
            && ($details['reason'] ?? null) === 'destructive_consent_required';
    }

    /** @return list<string> */
    private function dependents(GatewayApiException $exception): array
    {
        $dependents = $exception->details()['dependents'] ?? null;

        if (! is_array($dependents)) {
            return [];
        }

        return array_values(array_filter($dependents, is_string(...)));
    }
}
