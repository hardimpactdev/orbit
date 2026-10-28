<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Normalizes both live and recovered native hub receipts through the shared received hook. */
final readonly class NodeWireGuardOperationExecution implements OperationFamilyExecution
{
    public function context(Operation $operation): array
    {
        $descriptors = [['key' => 'prepare-wireguard', 'step' => 'prepare-wireguard', 'transport' => 'gateway', 'name' => 'node.prepare-wireguard',
            'input' => $this->prepareInput($operation), 'required' => true, 'resumable' => true]];
        if (isset($operation->input['_wireguard'])) {
            $descriptors[] = ['key' => 'hub-project', 'step' => 'hub-project', 'transport' => 'node-wireguard', 'name' => 'wireguard.project-hub',
                'input' => ValidatedData::object($operation->input['_wireguard']), 'required' => true, 'resumable' => false];
        }

        return ['descriptors' => $descriptors];
    }

    public function input(Operation $operation, array $run): array
    {
        return $run['name'] === 'node.prepare-wireguard' ? $this->prepareInput($operation)
            : ValidatedData::object($operation->input['_wireguard'] ?? []);
    }

    public function beforeRun(Operation $operation, array $run): void {}

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if ($run['name'] !== 'wireguard.project-hub') {
            return $receipt;
        }
        $error = $receipt['error_code'] ?? null;
        if ($error === null) {
            return ['projected' => true];
        }

        return ['error_code' => match ($error) {
            'wireguard.hub_install_failed' => 'vpn.server_config_install_failed',
            'wireguard.hub_config_invalid' => 'vpn.server_config_invalid',
            'wireguard.hub_start_failed' => 'vpn.server_start_failed',
            default => $error,
        }];
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): null
    {
        return null;
    }

    /** @return array<string, mixed> */
    private function prepareInput(Operation $operation): array
    {
        return ['context_node_id' => $operation->input['context_node_id'] ?? null, 'excluded_node_id' => $operation->input['excluded_node_id'] ?? null];
    }
}
