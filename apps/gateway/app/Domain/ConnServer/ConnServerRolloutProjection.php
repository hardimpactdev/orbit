<?php

declare(strict_types=1);

namespace App\Domain\ConnServer;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;

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

        return $this->direct->project($operation, $data, $result);
    }
}
