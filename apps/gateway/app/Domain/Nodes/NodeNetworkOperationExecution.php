<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Proves the public-to-private transition without letting the worker choose another Node. */
final readonly class NodeNetworkOperationExecution implements OperationFamilyChildren, OperationFamilyExecution
{
    public function __construct(private OperationChildren $children, private OperationStore $store, private VpnConfigurationRepository $configuration) {}

    public function context(Operation $operation): array
    {
        $descriptors = [$this->descriptor('network-prepare', 'gateway', 'node.prepare-network', ['roleless_operator' => $operation->input['roleless_operator']])];
        if (($operation->state['node_network_prepared'] ?? false) !== true) {
            return ['descriptors' => $descriptors];
        }
        $failure = null;
        foreach ($this->forward($operation) as $descriptor) {
            $descriptors[] = $descriptor;
            $receipt = $this->receipt($operation, $descriptor);
            if ($receipt === null) {
                return ['descriptors' => $descriptors];
            }
            if (isset($receipt['error_code'])) {
                $failure = $receipt;
                break;
            }
            if ($descriptor['key'] === 'network-key') {
                $child = $this->hub($operation);
                if ($child === null || ! $child->status->terminal()) {
                    return ['descriptors' => $descriptors];
                }
                if ($child->status !== OperationStatus::Succeeded) {
                    $failure = ['error_code' => $child->error_code ?? 'vpn.server_projection_failed'];
                    break;
                }
            }
        }
        if ($failure !== null && $this->needsRollback($operation)) {
            $descriptors[] = $this->rollback($operation);
            if ($this->receipt($operation, $this->rollback($operation)) === null) {
                return ['descriptors' => $descriptors];
            }
        }
        $descriptors[] = $this->descriptor('network-result', 'gateway', 'node.network-result', []);

        return ['descriptors' => $descriptors];
    }

    public function childContext(Operation $operation): array
    {
        $intents = [];
        $key = $this->receipt($operation, $this->key($operation));
        if ($key !== null && ! isset($key['error_code'])) {
            $intents = $this->intents($operation);
        }

        return ['intent' => ['hub_id' => $this->snapshot($operation)['hub_id'] ?? null], 'children' => $intents];
    }

    public function input(Operation $operation, array $run): array
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] === $run['key'] && $descriptor['name'] === $run['name'] && $descriptor['transport'] === $run['transport']) {
                return $descriptor['input'];
            }
        }
        throw $this->unproven();
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        $this->input($operation, $run);
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if (isset($receipt['error_code'])) {
            return $receipt;
        }
        if ($run['key'] === 'network-key') {
            $key = $receipt['public_key'] ?? null;
            $existing = $this->snapshot($operation)['existing_key'] ?? null;
            if (! is_string($key) || preg_match('/\A[A-Za-z0-9+\/]{43}=\z/D', $key) !== 1 || ($existing !== null && $key !== $existing)) {
                return ['error_code' => 'vpn.peer_key_mismatch'];
            }
            $node = Node::query()->findOrFail($operation->node_id);
            $this->configuration->assignPeerKey($node, $key);
            $this->store->change($operation, ['state' => [...($operation->state ?? []), 'network_public_key' => $key]]);
        }
        if ($run['key'] === 'network-private-ssh') {
            $expected = $this->snapshot($operation)['architecture'] ?? null;
            if (! is_string($expected) || ($receipt['architecture'] ?? null) !== $expected) {
                return ['error_code' => 'node.architecture_mismatch'];
            }
        }
        if ($run['key'] === 'network-trust' && ($receipt['matches'] ?? null) !== true) {
            return ['error_code' => 'node.firewall_verification_failed'];
        }

        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status === OperationStatus::Cancelled && $outcome->result === []) {
            return;
        }
        if (! OperationChildIntent::same($this->result($operation), $outcome->result ?? [])) {
            throw $this->unproven();
        }
    }

    public function cancellationBoundary(Operation $operation): array
    {
        // Once native publication succeeds, its owner must settle or restore the retained transaction.
        return ['key' => 'network-publish', 'transport' => 'bootstrap-ssh', 'name' => 'wireguard.publish-peer', 'result' => []];
    }

    /** @return array<string,mixed> */
    public function result(Operation $operation): array
    {
        if (($operation->state['node_network_prepared'] ?? false) !== true) {
            throw $this->unproven();
        }
        $failure = null;
        foreach ($this->forward($operation) as $descriptor) {
            $receipt = $this->receipt($operation, $descriptor);
            if ($receipt === null) {
                throw $this->unproven();
            }
            if (isset($receipt['error_code'])) {
                $failure = ['error_code' => $receipt['error_code'], 'failed_step' => $descriptor['key']];
                break;
            }
            if ($descriptor['key'] === 'network-key') {
                $child = $this->hub($operation);
                if ($child === null || ! $child->status->terminal()) {
                    throw $this->unproven();
                }
                if ($child->status !== OperationStatus::Succeeded) {
                    $failure = ['error_code' => $child->error_code ?? 'vpn.server_projection_failed', 'failed_step' => 'network-hub'];
                    break;
                }
            }
        }
        if ($failure !== null && $this->needsRollback($operation)) {
            $rollback = $this->receipt($operation, $this->rollback($operation));
            if ($rollback === null) {
                throw $this->unproven();
            }
            if (isset($rollback['error_code'])) {
                $failure = ['error_code' => 'vpn.peer_rollback_failed', 'failed_step' => 'network-rollback'];
            }
        }
        if ($failure !== null) {
            return ['network_proven' => true, ...$failure];
        }
        $key = $operation->state['network_public_key'] ?? null;
        if (! is_string($key)) {
            throw $this->unproven();
        }

        return ['network_proven' => true, 'public_key' => $key, 'retained' => (ValidatedData::object($this->snapshot($operation)['peer'] ?? [])['retain'] ?? false) === true];
    }

    /** @return list<array{key:string,step:string,transport:string,name:string,input:array<string,mixed>,required:bool,resumable:bool}> */
    private function forward(Operation $operation): array
    {
        $peer = ValidatedData::object($this->snapshot($operation)['peer'] ?? []);
        $peer['peer_public_key'] = $operation->state['network_public_key'] ?? null;

        return [$this->key($operation), $this->bootstrap($operation, 'network-publish', 'wireguard.publish-peer', $peer),
            $this->descriptor('network-private-ssh', 'agent', 'agent.status', []),
            $this->descriptor('network-trust', 'agent', 'firewall.rules', ValidatedData::object($this->snapshot($operation)['trust'] ?? []))];
    }

    /** @return array{key:string,step:string,transport:string,name:string,input:array<string,mixed>,required:bool,resumable:bool} */
    private function key(Operation $operation): array
    {
        return $this->bootstrap($operation, 'network-key', 'wireguard.prepare-peer-key', ['existing' => ($this->snapshot($operation)['existing_key'] ?? null) !== null]);
    }

    /** @return array{key:string,step:string,transport:string,name:string,input:array<string,mixed>,required:bool,resumable:bool} */
    private function rollback(Operation $operation): array
    {
        return $this->bootstrap($operation, 'network-rollback', 'wireguard.rollback-peer', []);
    }

    private function needsRollback(Operation $operation): bool
    {
        $published = $this->receipt($operation, $this->forward($operation)[1]);

        return (ValidatedData::object($this->snapshot($operation)['peer'] ?? [])['retain'] ?? false) === true && $published !== null && ! isset($published['error_code']);
    }

    private function hub(Operation $operation): ?Operation
    {
        $child = $operation->children()->where('child_key', 'network-hub')->first();
        if ($child !== null) {
            $this->children->verify($operation, $child);
            if (! OperationChildIntent::same(ValidatedData::object($child->child_admission['intent'] ?? []), $this->intents($operation)[0])) {
                throw $this->unproven();
            }
        }

        return $child;
    }

    /** @return list<array{key:string,kind:string,node_id:int|null,input:array<string,mixed>}> */
    private function intents(Operation $operation): array
    {
        $intent = ['key' => 'network-hub', 'kind' => 'node.wireguard_hub_converge', 'node_id' => ValidatedData::integer($this->snapshot($operation)['hub_id'] ?? null),
            'input' => ['context_node_id' => $operation->node_id, 'excluded_node_id' => null]];
        if (! OperationChildIntent::same(is_array($operation->state['child_intents'] ?? null) ? $operation->state['child_intents'] : [], [$intent])) {
            throw $this->unproven();
        }

        return [$intent];
    }

    /** @param array{key:string,step:string,transport:string,name:string,input:array<string,mixed>,required:bool,resumable:bool} $descriptor
     * @return array<string,mixed>|null
     */
    private function receipt(Operation $operation, array $descriptor): ?array
    {
        $run = ValidatedData::object($operation->state['executor_runs'] ?? [])[$descriptor['key']] ?? null;
        if (! is_array($run) || ! is_array($run['result'] ?? null)) {
            return null;
        }
        if (($run['name'] ?? null) !== $descriptor['name'] || ($run['transport'] ?? null) !== $descriptor['transport']
            || ! OperationChildIntent::same(ValidatedData::object($run['input'] ?? []), $descriptor['input'])) {
            throw $this->unproven();
        }

        return ValidatedData::object($run['result']);
    }

    /** @param array<string,mixed> $command
     * @return array{key:string,step:string,transport:string,name:string,input:array<string,mixed>,required:bool,resumable:bool}
     */
    private function bootstrap(Operation $operation, string $key, string $name, array $command): array
    {
        $input = array_intersect_key($operation->input, array_flip(['agent_pin', 'bootstrap_host', 'bootstrap_port', 'bootstrap_user', 'bootstrap_fingerprint', 'bootstrap_identity']));

        return $this->descriptor($key, 'bootstrap-ssh', $name, [...$input, 'command_input' => $command]);
    }

    /** @param array<string,mixed> $input
     * @return array{key:string,step:string,transport:string,name:string,input:array<string,mixed>,required:bool,resumable:bool}
     */
    private function descriptor(string $key, string $transport, string $name, array $input): array
    {
        return ['key' => $key, 'step' => $key, 'transport' => $transport, 'name' => $name, 'input' => $input, 'required' => false, 'resumable' => $transport === 'gateway'];
    }

    /** @return array<string,mixed> */
    private function snapshot(Operation $operation): array
    {
        return ValidatedData::object($operation->input['_network'] ?? []);
    }

    private function unproven(): ResourceOperationException
    {
        return new ResourceOperationException('operation.completion_unproven', 'The network transition requires its original runs and hub child.', 409);
    }
}
