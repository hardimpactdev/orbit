<?php

declare(strict_types=1);

namespace App\Domain\ConnServer;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Models\Tool;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Records the rollout's one report: the version, whether it was a dry run, and each Node's outcome (`updated`, `checked`, `held`,
 * `failed` or `not_reached`). A failed run keeps its `conn_server.*` code. Nothing is retried.
 */
final readonly class ConnServerRolloutProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['intent']), 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $raw = is_array($data['result'] ?? null) ? $data['result'] : [];
        $code = $raw['error_code'] ?? null;
        if (ValidatedData::string($data['status']) !== 'succeeded') {
            $data['error_code'] = is_string($code) && preg_match('/\Aconn_server\.[a-z_]{1,80}\z/D', $code) === 1 ? $code : 'conn_server.rollout_failed';
        }
        $result = array_intersect_key($raw, array_flip(['version', 'dry_run', 'nodes', 'error_code']));

        return DB::transaction(function () use ($operation, $data, $result): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $terminal = $operation->status->terminal();
            $operation = $this->direct->project($operation, $data, $result);
            $intent = ValidatedData::object($operation->input['intent']);
            if ($terminal || ! $operation->status->terminal() || ($intent['dry_run'] ?? true) !== false
                || ($result['version'] ?? null) !== ($intent['version'] ?? null) || ! is_string($intent['version'] ?? null)) {
                return $operation;
            }
            $admitted = [];
            foreach (is_array($intent['nodes'] ?? null) ? $intent['nodes'] : [] as $node) {
                if (is_array($node) && is_int($node['tool_id'] ?? null) && is_int($node['node_id'] ?? null)) {
                    $admitted[$node['node_id']] = $node['tool_id'];
                }
            }
            foreach (is_array($result['nodes'] ?? null) ? $result['nodes'] : [] as $observation) {
                if (is_array($observation) && is_int($observation['node_id'] ?? null) && ($observation['outcome'] ?? null) === 'updated'
                    && isset($admitted[$observation['node_id']])) {
                    Tool::query()->whereKey($admitted[$observation['node_id']])->where('node_id', $observation['node_id'])->update(['installed_version' => $intent['version']]);
                }
            }

            return $operation;
        });
    }
}
