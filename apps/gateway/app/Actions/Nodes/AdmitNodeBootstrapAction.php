<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Uses only the parent's admitted public connection and delivery pins. */
final readonly class AdmitNodeBootstrapAction
{
    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency, private OperationChildren $children, private OperationStore $store) {}

    /** @param array{key:string,kind:string,node_id:int|null,input:array<string,mixed>} $intent */
    public function execute(Operation $parent, array $intent): Operation
    {
        if (($existing = $this->idempotency->replay($intent['kind'])) !== null) {
            return $existing;
        }
        $probe = $intent['kind'] === 'node.bootstrap_probe';
        $keys = ['node.bootstrap_probe' => 'add-bootstrap-probe', 'node.bootstrap_install' => 'add-bootstrap-install',
            'node.bootstrap_host' => 'add-bootstrap-host', 'node.bootstrap_verify' => 'add-bootstrap-verify', 'node.macos_inspect' => 'add-macos-inspect'];
        if (! isset($keys[$intent['kind']])
            || $parent->kind !== 'node.add' || $this->idempotency->parent?->id !== $parent->id
            || $intent['node_id'] !== $parent->node_id || $intent['input'] !== []
            || $intent['key'] !== $keys[$intent['kind']]) {
            throw $this->forbidden();
        }
        $node = Node::query()->findOrFail($parent->node_id);
        $input = [];
        foreach (['bootstrap_host', 'bootstrap_port', 'bootstrap_user', 'bootstrap_fingerprint', 'bootstrap_identity'] as $field) {
            if (! array_key_exists($field, $parent->input)) {
                throw $this->forbidden();
            }
            $input[$field] = $parent->input[$field];
        }
        if ($probe) {
            if (! array_key_exists('requested_architecture', $parent->input)) {
                throw $this->forbidden();
            }
            $input['command_input'] = ['expected_architecture' => $parent->input['requested_architecture']];
        } else {
            $evidence = $this->children->requireSucceeded($parent, 'add-bootstrap-probe');
            if ($evidence->kind !== 'node.bootstrap_probe' || $evidence->node_id !== $node->id
                || ($evidence->result['probed'] ?? null) !== true || ($evidence->result['architecture'] ?? null) !== $node->architecture
                || ($evidence->result['host_key_fingerprint'] ?? null) !== $input['bootstrap_fingerprint']) {
                throw $this->forbidden();
            }
            $pins = ValidatedData::object($parent->input['agent_pins'] ?? []);
            $pin = $pins[AgentDelivery::key($node)] ?? null;
            if (! is_string($pin) || preg_match('/\A[0-9a-f]{64}\z/D', $pin) !== 1) {
                throw new ResourceOperationException('node.agent_delivery_missing', 'The admitted delivery has no build for the proven Node architecture.', 409);
            }
            $input['agent_pin'] = $pin;
            $input['command_input'] = [];
        }

        $plan = null;
        if (! in_array($intent['kind'], ['node.bootstrap_probe', 'node.bootstrap_install'], true)) {
            $installed = $this->children->requireSucceeded($parent, 'add-bootstrap-install');
            if ($installed->kind !== 'node.bootstrap_install' || $installed->node_id !== $node->id
                || ($installed->result['installed'] ?? null) !== true || ($installed->input['agent_pin'] ?? null) !== $input['agent_pin']) {
                throw $this->forbidden();
            }
            $plan = [];
            if ($intent['kind'] === 'node.bootstrap_host') {
                if ($node->platform !== 'linux' || ! is_bool($parent->input['skip_bootstrap'] ?? null)) {
                    throw $this->forbidden();
                }
                $input['command_input'] = ValidatedData::object($parent->input['bootstrap_command'] ?? null);
                if ($parent->input['skip_bootstrap'] !== true) {
                    $plan[] = $this->run('bootstrap-host', 'node.bootstrap', $input, []);
                }
            } else {
                $input['bootstrap_user'] = ValidatedData::string($parent->input['managed_user'] ?? null);
                if ($intent['kind'] === 'node.macos_inspect') {
                    if ($node->platform !== 'macos' || $input['bootstrap_user'] !== $parent->input['bootstrap_user']) {
                        throw $this->forbidden();
                    }
                    $input['command_input'] = ['address' => ValidatedData::string($parent->input['enrollment_address'] ?? null)];
                    $plan[] = $this->run('bootstrap-macos', 'node.inspect-macos-enrollment', $input, ['architecture' => $node->architecture]);
                } else {
                    $host = $this->children->requireSucceeded($parent, 'add-bootstrap-host');
                    if ($node->platform !== 'linux' || $host->kind !== 'node.bootstrap_host' || ($host->result['bootstrapped'] ?? null) !== true) {
                        throw $this->forbidden();
                    }
                    $plan[] = $this->run('bootstrap-verify', 'agent.status', $input,
                        ['architecture' => $node->architecture, 'platform' => 'linux', 'sha256' => $input['agent_pin']]);
                    $firewall = $parent->input['bootstrap_firewall'] ?? null;
                    if ($firewall !== null) {
                        $plan[] = $this->run('bootstrap-firewall', 'firewall.rules', [...$input, 'command_input' => ValidatedData::object($firewall)], ['matches' => true]);
                    }
                }
            }
            $input['_node_plan'] = $plan;
            $input['_node_result'] = [$intent['kind'] === 'node.bootstrap_host' ? 'bootstrapped' : 'bootstrap_verified' => true];
            $input['_node_failure_result'] = [];
        }
        $operation = $this->operations->execute($intent['kind'], 'node', $node->id, $node->id, $input, 'node-enrollment', $parent->request_id);
        if ($operation->wasRecentlyCreated && $plan !== null) {
            $this->store->change($operation, ['state' => [...($operation->state ?? []), 'child_intents' => []]]);
        }

        return $operation;
    }

    /** @param array<string,mixed> $input
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function run(string $key, string $name, array $input, array $result): array
    {
        return ['type' => 'run', 'key' => $key, 'transport' => 'bootstrap-ssh', 'name' => $name, 'input' => $input, 'result' => $result, 'optional' => false];
    }

    private function forbidden(): ResourceOperationException
    {
        return new ResourceOperationException('operation.child_forbidden', 'The bootstrap child differs from its owning enrollment intent.', 422);
    }
}
