<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Node-bound runtime withdrawal also works for an Instance with no Route row. */
final readonly class InstanceRuntimeExecution implements OperationFamilyExecution
{
    public function __construct(private DevelopmentSiteRepository $sites) {}

    public function context(Operation $operation): array
    {
        $commands = [['instance-runtime-prepare', 'gateway', 'route.instance-runtime', ['instance_id' => $operation->input['instance_id'], 'action' => 'prepare']]];
        $plan = $operation->state['instance_runtime_plan'] ?? null;
        if (is_array($plan)) {
            if (isset($plan['php'])) {
                $commands[] = ['instance-php-withdraw', 'agent', 'php.converge-shared-pools', ['configurations' => ['$secret' => 'route.php_pools', 'id' => $operation->id]]];
            }
            if (isset($plan['production_php'])) {
                $commands[] = ['instance-production-runtime-remove', 'agent', 'php.remove-production-runtime', ValidatedData::object($plan['production_php'])];
            }
            if (isset($plan['caddy'])) {
                $commands[] = ['instance-caddy-clean', 'route-caddy', 'caddy.publish', ValidatedData::object($plan['caddy'])];
                $commands[] = ['instance-certificate-clean', 'agent', 'certificate.remove-leaf', ValidatedData::object($plan['certificate'])];
            }
            $commands[] = ['instance-runtime-proof', 'gateway', 'route.instance-runtime', ['instance_id' => $operation->input['instance_id'], 'action' => 'prove']];
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
        throw new ResourceOperationException('operation.execution_forbidden', 'This runtime command is not admitted.', 422);
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        RouteExecutionProof::preceding($operation, $this->context($operation)['descriptors'], $run['key']);
        if ($run['name'] !== 'certificate.remove-leaf') {
            return;
        }
        $scope = ValidatedData::string($this->input($operation, $run)['scope']);
        if ($this->sites->forNode(Node::query()->findOrFail($operation->node_id))->contains(static fn (DevelopmentSite $site): bool => $site->loadsCertificate($scope))) {
            throw new ResourceOperationException('app-dev.certificate_in_use', 'A stored site still uses the Instance certificate.', 409);
        }
        $result = data_get($operation->state, 'executor_runs.instance-caddy-clean.result');
        if (! is_array($result) || isset($result['error_code']) || ! in_array($result['result'] ?? null, ['published', 'unchanged'], true)) {
            throw new ResourceOperationException('operation.completion_unproven', 'Withdraw Caddy before removing its certificate.', 409);
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if ($run['name'] === 'php.converge-shared-pools' && ! isset($receipt['error_code'])) {
            // A different site's missing directory must not keep this Instance's stale pool alive.
            if (! is_array($receipt['missing_pools'] ?? null)) {
                throw new ResourceOperationException('app-dev.php_result_invalid', 'PHP-FPM returned no pool receipt.', 409);
            }

            return ['withdrawn' => true];
        }

        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['transport'] !== 'gateway') {
                return ['key' => $descriptor['key'], 'transport' => $descriptor['transport'], 'name' => $descriptor['name'], 'result' => []];
            }
        }

        return null;
    }
}
