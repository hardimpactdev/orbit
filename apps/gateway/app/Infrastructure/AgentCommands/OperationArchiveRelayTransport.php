<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationDispatchNotStarted;
use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationFamilyArchiveRelay;
use App\Domain\Operations\OperationTransportInput;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Throwable;

/** Relays protected binary input between proven siblings; no archive bytes become public results.
 * @phpstan-import-type EffectRunInput from \App\Domain\Operations\SwampOperationExecutor
 */
final readonly class OperationArchiveRelayTransport implements OperationEffectTransport, OperationTransportInput
{
    public function __construct(private AgentCommandRunner $runner, private AgentContract $contract,
        private OperationChildren $children, private OperationExecutionContract $execution, private OperationAgentTransport $agent) {}

    public function schema(Operation $operation, array $run): array
    {
        $this->contract->operationCommand($run['name']);
        $native = AgentSchemas::inputSchema($run['name']);
        if (! is_array($native) || $native === []) {
            $this->refuse();
        }

        return ['type' => 'object', 'required' => ['relay', 'input'], 'additionalProperties' => false,
            '$defs' => $native['$defs'] ?? [], 'properties' => [
                'relay' => ['type' => 'object', 'required' => ['parent_operation_id', 'child_key', 'run_key'], 'additionalProperties' => false,
                    'properties' => ['parent_operation_id' => ['type' => 'integer', 'minimum' => 1],
                        'child_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 192],
                        'run_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 192]]],
                'input' => $native]];
    }

    public function wireInput(Operation $operation, array $run, #[\SensitiveParameter] array $input): mixed
    {
        return (object) ['relay' => (object) ValidatedData::object($input['relay'] ?? []),
            'input' => AgentSchemas::wireInput(ValidatedData::object($input['input'] ?? []))];
    }

    public function effectful(Operation $operation, array $run): bool
    {
        return true;
    }

    public function receipt(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        // Kit I reads only the destination receipt, even when source artifacts or Nodes are gone.
        return $this->agent->receipt($operation, $run, []);
    }

    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        $dispatched = false;
        try {
            return $this->relay($operation, $run, $input, $dispatched);
        } catch (Throwable $exception) {
            if (! $dispatched) {
                $code = $exception instanceof ResourceOperationException ? $exception->errorCode : 'operation.archive_unavailable';
                throw new OperationDispatchNotStarted($code, 'The archive relay refused before destination dispatch.', 409);
            }
            $this->refuse('operation.execution_uncertain');
        }
    }

    /** @param EffectRunInput $run
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function relay(Operation $operation, array $run, #[\SensitiveParameter] array $input, bool &$dispatched): array
    {
        $family = $this->execution->execution($operation);
        if (! $family instanceof OperationFamilyArchiveRelay) {
            $this->refuse();
        }
        $binding = $family->archiveRelay($operation, $run);
        $handle = ValidatedData::object($input['relay'] ?? []);
        $native = ValidatedData::object($input['input'] ?? []);
        $parent = $operation->parent_operation_id === null ? null : Operation::query()->find($operation->parent_operation_id);
        if ($parent === null || ($handle['parent_operation_id'] ?? null) !== $parent->id || ! is_string($handle['child_key'] ?? null)
            || ! is_string($handle['run_key'] ?? null)) {
            $this->refuse();
        }
        $this->children->verify($parent, $operation);
        $source = $this->children->requireSucceeded($parent, $handle['child_key']);
        if ($source->id === $operation->id) {
            $this->refuse();
        }
        $sourceRuns = ValidatedData::object($source->state['executor_runs'] ?? []);
        $producer = $sourceRuns[$handle['run_key']] ?? null;
        $facts = is_array($producer) ? ($producer['result'] ?? null) : null;
        if (! is_array($producer) || ($producer['transport'] ?? null) !== $binding['source_transport']
            || ($producer['name'] ?? null) !== $binding['source_name'] || ($producer['started'] ?? false) !== true
            || ($producer['receipt_state'] ?? null) !== 'succeeded' || ! is_array($facts) || isset($facts['error_code'])) {
            $this->refuse();
        }
        $read = $binding['read'];
        $reader = $this->contract->operationCommand($read['name']);
        $receiver = $this->contract->operationCommand($run['name']);
        $bytes = $facts['bytes'] ?? null;
        $digest = $facts['sha256'] ?? null;
        $artifactField = array_key_exists('archive', $read['input']) ? 'archive' : 'path';
        if (! is_string($read['input'][$artifactField] ?? null) || ($facts[$artifactField] ?? null) !== $read['input'][$artifactField]
            || ! is_int($bytes) || $bytes < 1 || ! is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || $binding['max_bytes'] < 1 || $bytes > min($binding['max_bytes'], $reader->outputMaxBytes, $receiver->inputMaxBytes, 1 << 40)
            || $reader->mutates || ! $receiver->mutates || $reader->inputMaxBytes !== 0 || $receiver->outputMaxBytes !== 0
            || ($native['bytes'] ?? null) !== $bytes || ($native['sha256'] ?? null) !== $digest
            || AgentSchemas::errors(AgentSchemas::wireInput($read['input']), AgentSchemas::inputSchema($read['name'])) !== []
            || AgentSchemas::errors(AgentSchemas::wireInput($native), AgentSchemas::inputSchema($run['name'])) !== []) {
            $this->refuse();
        }
        $sourceNode = $this->node($source);
        $destinationNode = $this->node($operation);
        $free = disk_free_space(sys_get_temp_dir());
        if ($free === false || $free < $bytes + max(64 << 20, ceil($bytes / 10))) {
            $this->refuse('operation.archive_unavailable');
        }
        $file = tmpfile();
        if ($file === false) {
            $this->refuse('operation.archive_unavailable');
        }
        try {
            $path = stream_get_meta_data($file)['uri'] ?? null;
            if (! is_string($path) || ! chmod($path, 0600) || ! unlink($path)) {
                $this->refuse('operation.archive_unavailable');
            }
            $output = new AgentBinaryOutput($file, $bytes);
            $received = $this->runner->run($sourceNode, $read['name'], $read['input'], output: $output);
            if ($output->bytes() !== $bytes || ! $this->matches($file, $digest)
                || ($received['bytes'] ?? null) !== $bytes || ($received['sha256'] ?? null) !== $digest) {
                $this->refuse('operation.archive_invalid');
            }
            // Cancellation and target identity can change during a large read. Never upload past that boundary.
            $operation->refresh();
            if (! $this->execution->cancellationAllowsRun($operation)) {
                $this->refuse('operation.cancelled');
            }
            $this->children->verify($parent, $operation);
            $this->node($source);
            $this->node($operation);

            $binary = AgentBinaryInput::fromStream($file, $bytes);
            $destinationInput = AgentEffectReceipts::input($run, $native);
            $dispatched = true;

            return $this->runner->run($destinationNode, $run['name'], $destinationInput, binaryInput: $binary);
        } finally {
            fclose($file);
        }
    }

    /** @param resource $file */
    private function matches(mixed $file, string $expected): bool
    {
        rewind($file);
        $hash = hash_init('sha256');

        hash_update_stream($hash, $file);

        return hash_final($hash) === $expected;
    }

    private function node(Operation $operation): Node
    {
        $operation->refresh();
        $id = $this->children->nodeId($operation);

        return ($id !== null && $operation->node_id === $id ? Node::query()->find($id) : null)
            ?? throw new ResourceOperationException('operation.target_missing', 'The admitted target Node no longer exists.', 409);
    }

    private function refuse(string $code = 'operation.archive_forbidden'): never
    {
        throw new ResourceOperationException($code, 'The archive relay could not be verified.', 409);
    }
}
