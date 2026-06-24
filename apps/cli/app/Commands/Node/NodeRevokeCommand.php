<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\Concerns\WithStepTree;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use RuntimeException;

final class NodeRevokeCommand extends GatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'node:revoke
        {consuming_node? : Name of the node whose access is being revoked}
        {serving_node? : Name of the node providing access}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Revoke one node\'s access to another.';

    public function handle(): int
    {
        $consumingNode = $this->stringArgument('consuming_node');
        $servingNode = $this->stringArgument('serving_node');

        if ($consumingNode === null) {
            return $this->renderFailure('validation_failed', 'Consuming node is required.', [
                'field' => 'consuming_node',
            ]);
        }

        if ($servingNode === null) {
            return $this->renderFailure('validation_failed', 'Serving node is required.', ['field' => 'serving_node']);
        }

        if (! (bool) $this->option('force')) {
            return $this->renderFailure('validation_failed', 'Use --force to revoke this grant.', ['field' => 'force']);
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->revokeGrant($consumingNode, $servingNode);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderRevocationTree($consumingNode, $servingNode);
    }

    private function renderRevocationTree(string $consumingNode, string $servingNode): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Revoking Grant',
            [
                ['label' => 'Validate nodes', 'doneLabel' => 'Validated nodes'],
                ['label' => 'Verify authorization', 'doneLabel' => 'Verified authorization'],
                ['label' => 'Revoke access', 'doneLabel' => 'Revoked access'],
            ],
            work: function () use ($consumingNode, $servingNode, &$response): array {
                return $response = $this->revokeGrantForHuman($consumingNode, $servingNode);
            },
            doneFooter: function () use ($consumingNode, $servingNode, &$response): string {
                return $this->alreadyAbsent($response)
                    ? "Access from '{$consumingNode}' to '{$servingNode}' was already revoked"
                    : "Access from '{$consumingNode}' to '{$servingNode}' revoked";
            },
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        if ($this->selfLockout($response)) {
            $this->line('  This machine no longer has Orbit gateway access.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function revokeGrant(string $consumingNode, string $servingNode): array
    {
        return $this->gatewayPost('/api/nodes/revoke', [
            'consuming_node' => $consumingNode,
            'serving_node' => $servingNode,
            'force' => true,
        ]);
    }

    /**
     * Run the revocation inside the progress tree, re-throwing gateway failures
     * with their operator-facing message so the failed footer renders the
     * documented prose rather than a JSON envelope.
     *
     * @return array<string, mixed>
     */
    private function revokeGrantForHuman(string $consumingNode, string $servingNode): array
    {
        try {
            return $this->revokeGrant($consumingNode, $servingNode);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function alreadyAbsent(array $response): bool
    {
        return ($this->successData($response)['already_absent'] ?? null) === true;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function selfLockout(array $response): bool
    {
        return ($this->successData($response)['self_lockout'] ?? null) === true;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successData(array $response): array
    {
        $data = $response['success']['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
