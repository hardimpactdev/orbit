<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use JsonException;
use Orbit\Sdk\Requests\Tasks\AnalyzeTaskGroupRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use stdClass;

final class AnalyzeTaskGroupCommand extends TaskCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'tasks:analyze
        {group? : Numeric task group ID}
        {--subtask= : Numeric current subtask ID}
        {--plan= : JSON file containing the complete native typed manifest}
        {--plan-sha256= : SHA-256 of the canonical manifest}
        {--base= : Full provisioned initial merge-base object ID}
        {--head= : Full candidate HEAD object ID}
        {--tree= : Full whole-worktree object ID, including untracked files}
        {--idempotency-key= : Required key reused when retrying this start}
        {--json : Return the complete final operation as machine-readable JSON}';

    #[\Override]
    protected $description = 'Analyze repository documentation impact without starting an agent or approving work.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $group = $this->idArgument('group', 'Task group', 'group');
        $subtask = $this->idOption('subtask', 'Subtask', 'subtask');
        $path = $this->textInput($this->option('plan'), 'Plan JSON file', 'plan', 4096);
        $digest = $this->textInput($this->option('plan-sha256'), 'Canonical plan SHA-256', 'plan_sha256', 64);
        $base = $this->textInput($this->option('base'), 'Initial analysis base', 'base', 64);
        $head = $this->textInput($this->option('head'), 'Candidate HEAD', 'head', 64);
        $tree = $this->textInput($this->option('tree'), 'Whole-worktree object ID', 'tree', 64);
        $key = $this->textInput($this->option('idempotency-key'), 'Idempotency key', 'idempotency_key', 128);
        if (in_array(false, [$group, $subtask, $path, $digest, $base, $head, $tree, $key], true)) {
            return self::FAILURE;
        }
        $path ??= $this->promptText('Plan JSON file', 4096);
        $digest ??= $this->promptText('Canonical plan SHA-256', 64);
        $base ??= $this->promptText('Initial analysis base', 64);
        $head ??= $this->promptText('Candidate HEAD', 64);
        $tree ??= $this->promptText('Whole-worktree object ID', 64);
        $key ??= $this->promptText('Idempotency key', 128);
        if (preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1 || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $key) !== 1
            || array_any([$base, $head, $tree], static fn (string $oid): bool => preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/D', $oid) !== 1)) {
            return $this->renderGatewayFailure('tasks.analysis_input_invalid', 'Supply full lowercase object IDs, a SHA-256 digest, and a valid idempotency key.');
        }
        $plan = is_file($path) && is_readable($path) ? file_get_contents($path, length: 49153) : false;
        try {
            if (! is_string($plan) || strlen($plan) > 49152 || ! json_decode($plan, false, flags: JSON_THROW_ON_ERROR) instanceof stdClass) {
                throw new JsonException;
            }
        } catch (JsonException) {
            return $this->renderGatewayFailure('tasks.plan_invalid', 'Plan must be a readable bounded UTF-8 JSON object.');
        }
        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $connector = $connectors->make($profile);
        $group ??= $this->selectGroup($connector, ['running', 'reviewing', 'settling', 'waiting_for_review']);
        if ($group === null) {
            return self::FAILURE;
        }
        if ($subtask === null) {
            $record = $this->loadGroup($connector, $group);
            $subtask = $record === null ? null : $this->selectSubtask($record, ['todo', 'running', 'reviewing']);
        }
        if ($subtask === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, new AnalyzeTaskGroupRequest($group, $subtask, $plan, $digest, $base, $head, $tree, $key), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Analyze task group [{$group}]", 'Analysis')
            : self::FAILURE;
    }
}
