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

final readonly class OfflineResidueExecution implements OperationFamilyExecution
{
    public function __construct(private DevelopmentSiteRepository $sites) {}

    public function context(Operation $operation): array
    {
        $commands = [['offline-residue-prepare', 'gateway', 'route.offline-residue', ['removed_node_id' => $operation->input['removed_node_id'], 'action' => 'prepare']]];
        $plan = $operation->state['offline_residue_plan'] ?? null;
        if (is_array($plan)) {
            if (isset($plan['caddy'])) {
                $commands[] = ['offline-residue-caddy', 'route-caddy', 'caddy.publish', ValidatedData::object($plan['caddy'])];
            }
            if (isset($plan['php'])) {
                $commands[] = ['offline-residue-php', 'agent', 'php.converge-shared-pools', ['configurations' => ['$secret' => 'route.php_pools', 'id' => $operation->id]]];
            }
            foreach (RouteExecutionProof::objects($plan['certificates']) as $index => $input) {
                $commands[] = ["offline-residue-certificate-{$index}", 'agent', 'certificate.remove-leaf', $input];
            }
            foreach (RouteExecutionProof::objects($plan['firewall']) as $index => $input) {
                $commands[] = ["offline-residue-firewall-{$index}", 'agent', 'firewall.route-lan', $input];
            }
            if (isset($plan['dns'])) {
                $commands[] = ['offline-residue-dns', 'route-dns', 'route.publish-dns', ValidatedData::object($plan['dns'])];
            }
            $commands[] = ['offline-residue-proof', 'gateway', 'route.offline-residue', ['removed_node_id' => $operation->input['removed_node_id'], 'action' => 'prove']];
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
        throw new ResourceOperationException('operation.execution_forbidden', 'This residue command is not admitted.', 422);
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        RouteExecutionProof::preceding($operation, $this->context($operation)['descriptors'], $run['key']);
        if ($run['name'] === 'certificate.remove-leaf') {
            $scope = ValidatedData::string($this->input($operation, $run)['scope']);
            if ($this->sites->forNode(Node::query()->findOrFail($operation->node_id))->contains(static fn (DevelopmentSite $site): bool => $site->loadsCertificate($scope))) {
                throw new ResourceOperationException('app-dev.certificate_in_use', 'A live site still uses this certificate.', 409);
            }
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if ($run['name'] === 'caddy.publish' && ! isset($receipt['error_code']) && ! in_array($receipt['result'] ?? null, ['published', 'unchanged'], true)) {
            throw new ResourceOperationException('caddy.result_invalid', 'No Caddy withdrawal receipt was returned.', 409);
        }
        if ($run['name'] === 'route.publish-dns' && ! isset($receipt['error_code'])) {
            if (($receipt['outcome'] ?? null) === 'refused') {
                return ['error_code' => data_get($receipt, 'data.error_code', 'private_dns.publication_failed')];
            }
            if (($receipt['outcome'] ?? null) !== 'published' || (data_get($receipt, 'data.ready', false)) !== true) {
                throw new ResourceOperationException('private_dns.result_invalid', 'No DNS withdrawal receipt was returned.', 409);
            }
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
