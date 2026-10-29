<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Uses only the parent's admitted public connection and delivery pins. */
final readonly class AdmitNodeBootstrapAction
{
    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency, private OperationChildren $children) {}

    /** @param array{key:string,kind:string,node_id:int|null,input:array<string,mixed>} $intent */
    public function execute(Operation $parent, array $intent): Operation
    {
        if (($existing = $this->idempotency->replay($intent['kind'])) !== null) {
            return $existing;
        }
        $probe = $intent['kind'] === 'node.bootstrap_probe';
        if (! in_array($intent['kind'], ['node.bootstrap_probe', 'node.bootstrap_install'], true)
            || $parent->kind !== 'node.add' || $this->idempotency->parent?->id !== $parent->id
            || $intent['node_id'] !== $parent->node_id || $intent['input'] !== []
            || $intent['key'] !== ($probe ? 'add-bootstrap-probe' : 'add-bootstrap-install')) {
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

        return $this->operations->execute($intent['kind'], 'node', $node->id, $node->id, $input, 'node-enrollment', $parent->request_id);
    }

    private function forbidden(): ResourceOperationException
    {
        return new ResourceOperationException('operation.child_forbidden', 'The bootstrap child differs from its owning enrollment intent.', 422);
    }
}
