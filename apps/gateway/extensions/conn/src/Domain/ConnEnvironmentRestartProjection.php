<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * Records a T3 restart for the machine name from the run's one report: `restarted`, `skipped` when the container stopped or froze after
 * the Gateway's read, or `failed` with the `conn.*` code of a T3 that did not come back. Nothing is retried.
 */
final readonly class ConnEnvironmentRestartProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        $node = Node::query()->findOrFail($operation->node_id);

        // No secret goes in; the dispatcher sends an empty map as `{}`.
        return ['intent' => [...ValidatedData::object($operation->input['intent']), 'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($node)],
            'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = ValidatedData::string($data['status']);
        $raw = is_array($data['result'] ?? null) ? $data['result'] : [];
        $result = [];
        if ($status === 'succeeded') {
            $answer = $raw['answer'] ?? null;
            $reason = $raw['reason'] ?? null;
            $result = match (true) {
                $answer === 'restarted' => ['answer' => 'restarted'],
                $answer === 'skipped' && in_array($reason, ['frozen', 'stopped'], true) => ['answer' => 'skipped', 'reason' => $reason],
                default => throw new ResourceOperationException('operation.result_invalid', 'The restart report names no answer.', 422),
            };
        } elseif (in_array($status, ['failed', 'interrupted'], true)) {
            $code = $raw['error_code'] ?? null;
            $code = is_string($code) && preg_match('/\Aconn\.[a-z_]{1,80}\z/D', $code) === 1 ? $code : 'conn.t3_restart_failed';
            $data['error_code'] = $code;
            $result = ['answer' => 'failed', 'error_code' => $code];
        }

        return $this->direct->project($operation, $data, $result);
    }
}
