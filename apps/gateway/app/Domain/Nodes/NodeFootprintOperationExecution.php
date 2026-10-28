<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Exposes the next footprint effect, retaining earlier intents for exact child proof lookup.
 * @phpstan-import-type Descriptor from OperationFamilyExecution
 * @phpstan-import-type ChildIntent from OperationFamilyChildren
 *
 * @phpstan-type Effect array{type:'run',descriptor:Descriptor}|array{type:'child',intent:ChildIntent}
 */
final readonly class NodeFootprintOperationExecution implements OperationFamilyChildren, OperationFamilyExecution
{
    public function __construct(private OperationChildren $children, private NodeFootprintEvidence $evidence) {}

    public function context(Operation $operation): array
    {
        $descriptors = [$this->descriptor('prepare-footprint', 'gateway', 'node.prepare-footprint', [
            'force_artifacts' => $operation->input['force_artifacts'], 'first_visit' => $operation->input['first_visit'],
        ])];
        foreach ($this->eligible($operation) as $effect) {
            if ($effect['type'] === 'run') {
                $descriptors[] = $effect['descriptor'];
            }
        }
        $descriptors[] = $this->descriptor('footprint-result', 'gateway', 'node.footprint-result', []);

        return ['descriptors' => $descriptors];
    }

    public function childContext(Operation $parent): array
    {
        $intents = [];
        $recorded = $parent->state['child_intents'] ?? [];
        if (! is_array($recorded) || ! array_is_list($recorded)) {
            throw $this->unproven();
        }
        foreach ($this->eligible($parent) as $effect) {
            if ($effect['type'] !== 'child') {
                continue;
            }
            if (! array_any($recorded, static fn (mixed $intent): bool => is_array($intent) && OperationChildIntent::same($effect['intent'], $intent))) {
                throw $this->unproven();
            }
            $intents[] = $effect['intent'];
        }

        return ['intent' => ['node_id' => $parent->node_id], 'children' => $intents];
    }

    public function input(Operation $operation, array $run): array
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] === $run['key'] && $descriptor['transport'] === $run['transport'] && $descriptor['name'] === $run['name']) {
                return $descriptor['input'];
            }
        }

        throw $this->unproven();
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        $this->input($operation, $run);
        if ($run['key'] === 'footprint-result') {
            $this->evidence->derive($operation, $operation->children()->get());
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if ($run['key'] === 'footprint-agent-install' && $run['transport'] === 'node-agent' && $run['name'] === 'agent.install'
            && ($receipt['installed'] ?? null) === true && ! isset($receipt['error_code'])) {
            $this->input($operation, $run);
            Node::query()->whereKey($operation->node_id)->update(['agent_failed_pin' => null]);
        }

        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) {
            return;
        }
        $derived = $this->evidence->derive($operation, $operation->children()->get());
        if ($derived['error_code'] !== null || $outcome->result === null || ! OperationChildIntent::same($derived['result'], $outcome->result)) {
            throw $this->unproven();
        }
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    /** @return list<Effect> */
    private function eligible(Operation $operation): array
    {
        if (($operation->state['node_footprint_prepared'] ?? false) !== true) {
            return [];
        }
        $eligible = [];
        foreach ($this->effects($operation) as $effect) {
            $eligible[] = $effect;
            if ($effect['type'] === 'child') {
                $child = $operation->children()->where('child_key', $effect['intent']['key'])->first();
                if ($child === null) {
                    break;
                }
                $this->children->verify($operation, $child);
                if (! $child->status->terminal()) {
                    break;
                }
                $key = $effect['intent']['key'];
                $skip = $child->status === OperationStatus::Failed && ($key === 'footprint-route-residue'
                    || ($key === 'footprint-caddy' && in_array($child->result['stage'] ?? null, ['render', 'validate'], true)));
                if ($child->status !== OperationStatus::Succeeded && ! $skip) {
                    break;
                }
            } else {
                $descriptor = $effect['descriptor'];
                $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);
                $receipt = $runs[$descriptor['key']] ?? null;
                if (! is_array($receipt) || ($receipt['transport'] ?? null) !== $descriptor['transport'] || ($receipt['name'] ?? null) !== $descriptor['name']
                    || ! is_array($receipt['result'] ?? null) || isset($receipt['result']['error_code'])) {
                    break;
                }
                if (in_array($descriptor['key'], ['footprint-agent-install', 'footprint-private-dns-listener'], true)
                    && ($receipt['result']['installed'] ?? null) !== true) {
                    break;
                }
            }
        }

        return $eligible;
    }

    /** @return list<Effect> */
    private function effects(Operation $operation): array
    {
        $snapshot = ValidatedData::object($operation->input['_footprint'] ?? []);
        $plan = $snapshot['artifacts'] ?? [];
        if (! is_array($plan) || ! array_is_list($plan)) {
            throw $this->unproven();
        }
        $payloads = ValidatedData::object($snapshot['payloads'] ?? []);
        $children = ValidatedData::object($snapshot['children'] ?? []);
        $effects = [];
        foreach ($plan as $entry) {
            $artifact = ValidatedData::object($entry);
            if (($artifact['required'] ?? false) !== true) {
                continue;
            }
            $name = ValidatedData::string($artifact['name']);
            if ($name === 'agent') {
                $effects[] = ['type' => 'run', 'descriptor' => $this->descriptor('footprint-agent-install', 'node-agent', 'agent.install', ['pin' => ValidatedData::string($snapshot['agent_pin'] ?? null)])];
                $effects[] = ['type' => 'run', 'descriptor' => $this->descriptor('prepare-agent-configuration', 'gateway', 'node.prepare-agent-configuration', [])];
            }
            if (isset($children[$name])) {
                if ($name === 'private-dns') {
                    $effects[] = ['type' => 'run', 'descriptor' => $this->descriptor('footprint-private-dns-listener', 'agent', 'node.install-private-dns-listener', ValidatedData::object($payloads['private-dns-listener'] ?? []))];
                }
                $child = ValidatedData::object($children[$name]);
                $effects[] = ['type' => 'child', 'intent' => ['key' => 'footprint-'.$name, 'kind' => ValidatedData::string($child['kind']),
                    'node_id' => ValidatedData::integer($child['node_id']), 'input' => ValidatedData::object($child['input'])]];
            } else {
                $command = match ($name) {
                    'agent' => 'agent.configure', 'caddy-package' => 'caddy.install-package',
                    'peer-dns' => 'wireguard.repair-peer-dns', 'tmpfiles' => 'node.publish-tmpfiles',
                    default => throw $this->unproven(),
                };
                $input = ValidatedData::object($payloads[$name] ?? []);
                if ($name === 'agent') {
                    $input['binary_changed'] = ($operation->state['node_agent_configuration_changed'] ?? false) === true;
                }
                $effects[] = ['type' => 'run', 'descriptor' => $this->descriptor('footprint-'.$name, 'agent', $command, $input)];
            }
        }

        return $effects;
    }

    /** @param array<string, mixed> $input
     * @return Descriptor
     */
    private function descriptor(string $key, string $transport, string $name, array $input): array
    {
        return ['key' => $key, 'step' => $key, 'transport' => $transport, 'name' => $name,
            'input' => $input, 'required' => false, 'resumable' => $transport === 'gateway'];
    }

    private function unproven(): ResourceOperationException
    {
        return new ResourceOperationException('operation.completion_unproven', 'The next footprint effect requires its recorded prerequisites.', 409);
    }
}
