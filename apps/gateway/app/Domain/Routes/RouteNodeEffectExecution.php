<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class RouteNodeEffectExecution implements OperationFamilyExecution
{
    public const array Kinds = ['route.lan_converge', 'route.lan_remove', 'route.workload_verify', 'route.public_verify',
        'route.public_firewall', 'route.shared_php_withdraw', 'route.transfer_php_withdraw', 'route.transfer_lan_remove'];

    public function context(Operation $operation): array
    {
        $commands = [['node-effect-prepare', 'gateway', 'route.node-effect', [...$operation->input, 'action' => 'prepare']]];
        $plan = $operation->state['route_effect_plan'] ?? null;
        if (is_array($plan)) {
            if (is_array($plan['php'] ?? null)) {
                $commands[] = ['node-effect-php', 'agent', 'php.converge-shared-pools', ['configurations' => ['$secret' => 'route.php_pools', 'id' => $operation->id]]];
            } elseif (is_string($plan['command'] ?? null)) {
                $commands[] = ['node-effect', 'agent', $plan['command'], ValidatedData::object($plan['input'] ?? [])];
            }
            $commands[] = ['node-effect-proof', 'gateway', 'route.node-effect', [...$operation->input, 'action' => 'prove']];
        }
        $descriptors = [];
        foreach ($commands as [$key, $transport, $name, $input]) {
            $descriptors[] = compact('key', 'transport', 'name', 'input') + ['step' => $key, 'resumable' => true, 'required' => true];
        }

        return ['descriptors' => $descriptors];
    }

    public function input(Operation $operation, array $run): array
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] === $run['key'] && $descriptor['name'] === $run['name'] && $descriptor['transport'] === $run['transport']) {
                return $descriptor['input'];
            }
        }
        throw new ResourceOperationException('operation.execution_forbidden', 'This Node effect was not admitted.', 422);
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        RouteExecutionProof::preceding($operation, $this->context($operation)['descriptors'], $run['key']);
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if (isset($receipt['error_code']) || $run['transport'] === 'gateway') {
            return $receipt;
        }
        if ($run['name'] === 'php.converge-shared-pools' && ! is_array($receipt['missing_pools'] ?? null)) {
            throw new ResourceOperationException('app-dev.php_result_invalid', 'PHP-FPM returned no pool withdrawal receipt.', 409);
        }
        if (in_array($run['name'], ['firewall.rules', 'firewall.route-lan'], true) && ($receipt['matches'] ?? null) !== true) {
            return ['error_code' => 'node.firewall_convergence_failed'];
        }

        return ['ready' => true];
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        $descriptor = $this->context($operation)['descriptors'][1] ?? null;

        return $descriptor === null || $descriptor['transport'] === 'gateway' ? null
            : ['key' => $descriptor['key'], 'transport' => $descriptor['transport'], 'name' => $descriptor['name'], 'result' => []];
    }
}
