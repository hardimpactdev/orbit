<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Bounded public cleanup intent and result; native Swamp owns execution. */
final readonly class DatabaseCleanupProjection implements DirectOperationProjection
{
    /** @return array<string, mixed> */
    public function input(Operation $operation): array
    {
        if (in_array($operation->node_id, [1, 18], true)) {
            throw new ResourceOperationException('database.worker_transport_unavailable', 'SQLite cleanup requires a worker on the admitted Node.', 422);
        }
        $intent = DatabaseExecutionIntent::recorded($operation->input['intent_id'] ?? null);
        $command = $intent->payload['commands']['database-apply'] ?? null;
        if ($operation->kind !== 'database.sqlite_cleanup' || $operation->target_type !== 'instance'
            || $intent->target_type !== $operation->target_type || $intent->target_id !== $operation->target_id
            || $intent->node_id !== $operation->node_id
            || ! is_array($command)) {
            $this->invalid();
        }
        $input = $command['input'];
        if ($command['name'] === 'instance.copy-sqlite' && ($input['operation'] ?? null) === 'cleanup'
            && is_string($input['directory'] ?? null) && preg_match('#\A/tmp/orbit-sqlite-clone-[0-9a-f]{32}\z#D', $input['directory']) === 1) {
            return ['intent' => ['variant' => 'clone', 'directory' => $input['directory']], 'execution_secrets' => []];
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
            || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $safe['transport_user']) !== 1 || preg_match('/\A[0-9a-f]{64}\z/D', $safe['operation']) !== 1
            || $safe['snapshot_path'] !== '/tmp/orbit-sqlite-'.$safe['operation'].'.sqlite'
            || preg_match('#\A/var/lib/orbit/app-instance-sqlite-seeds/source-[1-9][0-9]*-target-'.$operation->target_id.'(?:-'.$safe['operation'].')?\z#D', $safe['state_dir']) !== 1) {
            $this->invalid();
        }

        return ['intent' => ['variant' => 'seed', 'target_instance_id' => $operation->target_id, ...$safe], 'execution_secrets' => []];
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
        $raw = ValidatedData::object($data['result'] ?? []);
        $result = [];
        if (($raw['backend'] ?? null) === 'absent') {
            $result['backend'] = 'absent';
        }
        if (is_string($raw['error_code'] ?? null) && preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $raw['error_code']) === 1) {
            $result['error_code'] = $raw['error_code'];
        }

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
