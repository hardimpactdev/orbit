<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseExecutionIntent;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Bounded public cleanup intent and result; native Swamp owns execution. */
final readonly class DatabaseCleanupProjection implements DirectOperationProjection
{
    /** @return array<string, mixed> */
    public function input(Operation $operation): array
    {
        $intent = DatabaseExecutionIntent::recorded($operation->input['intent_id'] ?? null);
        $command = $intent->payload['commands']['database-apply'] ?? null;
        if ($operation->kind !== 'database.sqlite_cleanup' || $operation->target_type !== 'instance'
            || $intent->target_type !== $operation->target_type || $intent->target_id !== $operation->target_id
            || $intent->node_id !== $operation->node_id || count($intent->payload['commands']) !== 1
            || ! is_array($command) || ($command['protected'] ?? []) !== []
            || ($intent->payload['projection']['children'] ?? []) !== []) {
            $this->invalid();
        }
        $node = Node::query()->findOrFail($operation->node_id);
        $owner = $node->user;
        $pin = $operation->input['agent_sha256'] ?? null;
        if (! is_string($pin) || preg_match('/\A[0-9a-f]{64}\z/D', $pin) !== 1) {
            $this->invalid();
        }
        $input = $command['input'];
        if ($command['name'] === 'instance.copy-sqlite' && ($input['operation'] ?? null) === 'cleanup'
            && is_string($input['directory'] ?? null) && preg_match('#\A/tmp/orbit-sqlite-clone-[0-9a-f]{32}\z#D', $input['directory']) === 1) {
            return ['variant' => 'clone', 'agent_sha256' => $pin, 'directory' => $input['directory']];
        }
        if ($command['name'] !== 'instance.prepare-sqlite-seed' || ($input['mode'] ?? null) !== 'cleanup') {
            $this->invalid();
        }
        $safe = [];
        foreach (['base', 'source_path', 'source_user', 'transport_user', 'operation', 'state_dir', 'snapshot_path'] as $key) {
            if (! is_string($input[$key] ?? null)) {
                $this->invalid();
            }
            $safe[$key] = $input[$key];
        }
        if (! $this->path($safe['base']) || ! $this->path($safe['source_path'])
            || ! str_starts_with($safe['source_path'], $safe['base'].'/')
            || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $safe['source_user']) !== 1
            || $safe['transport_user'] !== $owner || preg_match('/\A[0-9a-f]{64}\z/D', $safe['operation']) !== 1
            || $safe['snapshot_path'] !== '/tmp/orbit-sqlite-'.$safe['operation'].'.sqlite'
            || preg_match('#\A/var/lib/orbit/app-instance-sqlite-seeds/source-[1-9][0-9]*-target-'.$operation->target_id.'(?:-'.$safe['operation'].')?\z#D', $safe['state_dir']) !== 1) {
            $this->invalid();
        }

        return ['variant' => 'seed', 'target_instance_id' => $operation->target_id, 'agent_sha256' => $pin, ...$safe];
    }

    private function path(string $path): bool
    {
        return str_starts_with($path, '/') && ! preg_match('#[\x00\r\n]|(?:^|/)\.{1,2}(?:/|$)|//#', $path);
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('database.cleanup_invalid', 'The cleanup does not match its admitted database snapshot.', 422);
    }

    /** @param array<string, mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['backend', 'error_code']));
        $status = ValidatedData::string($data['status']);
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        if (($status === 'succeeded' && (($result['backend'] ?? null) !== 'absent' || $code !== null || isset($result['error_code'])))
            || (in_array($status, ['running', 'cancelled'], true) && ($result !== [] || $code !== null))
            || ($status === 'failed' && ($result !== ['error_code' => $code] || ! in_array($code, ['agent.version_mismatch', 'instance.sqlite_seed_refused', 'instance.sqlite_seed_failed', 'database.cleanup_owner_unavailable', 'database.sqlite_cleanup_refused', 'database.sqlite_cleanup_failed'], true)))) {
            throw new ResourceOperationException('operation.result_invalid', 'The cleanup needs its bounded Swamp outcome.', 422);
        }

        $this->input($operation);

        return app(DirectOperation::class)->project($operation, $data, $result, 'database-apply');
    }
}
