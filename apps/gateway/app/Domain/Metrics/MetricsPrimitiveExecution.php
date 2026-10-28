<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Models\Node;
use App\Models\Operation;

final readonly class MetricsPrimitiveExecution implements OperationFamilyExecution
{
    public function __construct(private CaddySiteCertificates $certificates) {}
    public function context(Operation $operation): array
    {
        $node = Node::query()->findOrFail($operation->node_id);
        [$name, $input] = match ($operation->kind) {
            'metrics.runtime_converge' => ['metrics.runtime', ['address' => $node->wireguard_ip, 'action' => ['$secret' => 'metrics.runtime', 'id' => $node->id]]],
            'metrics.runtime_remove' => ['metrics.runtime', ['address' => $node->wireguard_ip, 'action' => ['operation' => 'remove', 'purge_data' => $operation->input['purge_data']]]],
            'metrics.runtime_verify' => ['metrics.credentials', ['address' => $node->wireguard_ip, 'action' => ['$secret' => 'metrics.password', 'id' => $node->id]]],
            'metrics.certificate_publish' => ['metrics.certificate', ['operation' => 'publish', 'version' => substr(hash('sha256', 'metrics:'.$operation->id), 0, 16),
                'certificate' => ['$secret' => 'metrics.certificate', 'id' => $node->id], 'key' => ['$secret' => 'metrics.certificate', 'id' => $node->id]]],
            'metrics.certificate_remove' => ['metrics.certificate', ['operation' => 'remove']],
            'metrics.publication_firewall' => ['metrics.publication-firewall', ['address' => $node->wireguard_ip, 'action' => $operation->input['action']]],
        };
        return ['descriptors' => [['key' => 'metrics-effect', 'step' => 'metrics-effect', 'transport' => 'agent', 'name' => $name, 'input' => $input, 'resumable' => true, 'required' => true]]];
    }
    public function input(Operation $operation, array $run): array { return $this->context($operation)['descriptors'][0]['input']; }
    public function beforeRun(Operation $operation, array $run): void {}
    public function received(Operation $operation, array $run, array $receipt): array
    {
        if (isset($receipt['error_code'])) { return $receipt; }
        if ($operation->kind === 'metrics.certificate_publish') { $this->certificates->record($operation->node_id, 'metrics'); return ['applied' => true]; }
        if ($operation->kind === 'metrics.certificate_remove') { $this->certificates->forget($operation->node_id, 'metrics'); return ['applied' => true]; }
        return $receipt;
    }
    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}
    public function cancellationBoundary(Operation $operation): ?array { return null; }
}
