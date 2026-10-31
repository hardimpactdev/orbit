<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRoleRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class RemoveNodeRoleCommand extends NodeCommand
{
    use FollowsNodeRoleOperations;

    #[\Override]
    protected $signature = 'node:role:remove
        {node : Node ID or name}
        {role : Role name}
        {--force : Confirm destructive role removal and dependent cleanup}
        {--purge-data : Request supported role-owned data cleanup}
        {--offline : Remove the role from a node Orbit cannot reach}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove one role assignment from a node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $role = $this->stringArgument('role', 'Role', 'node_role.role_required');

        if ($role === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->resolveNodeId($connector, $this->argument('node'));

        if ($nodeId === null) {
            return self::FAILURE;
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
            OperationResponse::class,
            ['Remove Node role', 'Recording Node role removal', 'Node role removal recorded'],
            static fn (object $response): ProgressOutcome => new ProgressOutcome(ProgressState::Success, 'Node role removal recorded'),
        );

        if (! $response instanceof OperationResponse) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);

        return $profile === null ? self::FAILURE : $this->followOperation($connector, $profile, $response, 'Remove Node role', 'Node role removal');
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
                    ), OperationResponse::class);
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
