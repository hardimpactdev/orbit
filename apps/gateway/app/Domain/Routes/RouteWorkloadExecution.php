<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class RouteWorkloadExecution implements OperationFamilyExecution
{
    public function __construct(private RouteWorkloadInput $inputs) {}

    public function context(Operation $operation): array
    {
        $base = ['route_id' => $operation->input['route_id'], 'instance_id' => $operation->input['instance_id']];
        $commands = [['workload-prepare', 'gateway', 'route.workload', [...$base, 'action' => 'prepare']]];
        $plan = $operation->state['route_workload_plan'] ?? null;
        if (is_array($plan)) {
            if (is_array($plan['source_access'] ?? null)) {
                $commands[] = ['workload-source-access', 'route-workload', 'instance.grant-development-caddy-access', $plan['source_access']];
            }
            $reference = ['configurations' => ['$secret' => 'route.php_pools', 'id' => $operation->id]];
            $commands[] = ['workload-php-inspect', 'route-workload', 'php.inspect-shared-pools', $reference];
            // Package selection comes from the proven inspection, never the Swamp caller.
            if (isset($plan['packages'])) {
                if (is_array($plan['packages'])) {
                    $commands[] = ['workload-php-install', 'route-workload', 'php.install-build', ValidatedData::object($plan['packages']['install'])];
                    foreach (RouteExecutionProof::objects($plan['packages']['configure']) as $index => $input) {
                        $commands[] = ["workload-php-configure-{$index}", 'route-workload', 'php.configure-runtime', $input];
                    }
                }
                $commands[] = ['workload-php-pools', 'route-workload', 'php.converge-shared-pools', $reference];
                $commands[] = ['workload-proof', 'gateway', 'route.workload', [...$base, 'action' => 'prove']];
            }
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
        throw new ResourceOperationException('operation.execution_forbidden', 'This workload command was not admitted.', 422);
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        RouteExecutionProof::preceding($operation, $this->context($operation)['descriptors'], $run['key']);
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if (isset($receipt['error_code'])) {
            return $receipt;
        }
        if ($run['name'] === 'php.inspect-shared-pools') {
            $versions = $receipt['versions'] ?? null;
            if (! is_array($versions) || ! array_is_list($versions) || array_filter($versions, static fn ($version): bool => ! is_string($version)) !== [] || ! is_array($receipt['missing_pools'] ?? null)) {
                throw new ResourceOperationException('app-dev.php_result_invalid', 'PHP-FPM returned an invalid pool inspection.', 409);
            }
            $plan = ValidatedData::object($operation->state['route_workload_plan'] ?? []);
            $plan['packages'] = $this->inputs->packages(Node::query()->findOrFail($operation->node_id), $versions) ?? false;
            $operation->state = [...($operation->state ?? []), 'route_workload_plan' => $plan];

            return ['inspected' => true];
        }
        if ($run['name'] === 'php.converge-shared-pools') {
            if (! is_array($receipt['missing_pools'] ?? null)) {
                throw new ResourceOperationException('app-dev.php_result_invalid', 'PHP-FPM returned no pool receipt.', 409);
            }
            if ($receipt['missing_pools'] !== []) {
                return ['error_code' => 'app-dev.php_pool_directory_missing'];
            }
        }
        if ($run['name'] === 'php.install-build' && ! in_array($receipt['outcome'] ?? null, ['current', 'applied'], true)) {
            return ['error_code' => 'app-dev.php_install_failed'];
        }

        return $run['transport'] === 'gateway' ? $receipt : ['ready' => true];
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['transport'] !== 'gateway' && $descriptor['name'] !== 'php.inspect-shared-pools') {
                return ['key' => $descriptor['key'], 'transport' => $descriptor['transport'], 'name' => $descriptor['name'], 'result' => []];
            }
        }

        return null;
    }
}
