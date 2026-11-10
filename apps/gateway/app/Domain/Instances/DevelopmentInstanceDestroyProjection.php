<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\ProjectDirectInstanceDestroyAction;
use App\Domain\DatabaseServers\DatabaseOwnedRemoval;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Frozen public removal DAG input and terminal record projection only. */
final readonly class DevelopmentInstanceDestroyProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        $input = $operation->input;
        $sourceCapture = InstanceRemoval::query()->findOrFail(ValidatedData::string($input['removal_id']))->native_sources;
        $native = ValidatedData::object($input['native_destroy']);
        $secrets = ValidatedData::object($native['execution_secrets']);
        unset($native['execution_secrets']);
        $detail = ValidatedData::object($input['direct_destroy']);
        $database = ValidatedData::object($detail['database']);
        // Owned server databases drop through one database:destroy child per server, with that server's admin login.
        [$database['servers'], $serverCopies] = DatabaseOwnedRemoval::servers(ValidatedData::integer($database['intent_id']),
            $database['servers'] ?? [], ! $operation->status->terminal());
        $detail['database'] = $database;
        // Each member's hibernation files, so a removed Instance leaves no awake or cold marker or access log behind.
        // Rendered at run start, so a removal recorded earlier gets them too.
        $detail['hibernation'] = [];
        foreach (InstanceLifecycleInput::members($input) as $member) {
            $key = RuntimeHibernation::key(ValidatedData::integer($member['instance_id']));
            foreach ([RuntimeHibernation::awakePath($key), RuntimeHibernation::coldPath($key), RuntimeHibernation::accessLogPath($key)] as $path) {
                $detail['hibernation'][] = ['instance_id' => ValidatedData::integer($member['instance_id']), 'path' => $path];
            }
        }
        $public = [...Arr::only($input, ['container_destroy', 'removal_id', 'node_id', 'instance_id', 'project_id', 'name', 'force', 'inventory_digest', 'repository', 'source_pending', 'protected_source_roots', 'members']), 'direct_destroy' => $detail];
        $programs = ValidatedData::object(app(InstanceDestroyTeardownSecret::class)->resolve($operation, $operation->target_id, '', 'teardown')->value);
        $teardown = [];
        foreach (InstanceLifecycleInput::members($programs) as $member) {
            $instance = Instance::query()->findOrFail(ValidatedData::integer($member['instance_id']));
            foreach (InstanceLifecycleInput::members(['members' => $member['steps']]) as $step) {
                $command = ValidatedData::string($step['command']);
                $teardown[] = ['name' => $step['name'], 'command' => $command, 'sha256' => hash('sha256', $command), 'timeout_seconds' => $step['timeout_seconds'],
                    'directory' => $instance->checkout_path, 'instance_id' => $instance->id, 'instance_name' => $instance->name, 'seed_path' => '', 'seed_commit' => ''];
            }
        }
        $members = InstanceLifecycleInput::members($input);
        $fetches = $input['force'] !== true && array_filter($members, static fn (array $member): bool => ($member['failed_creation'] ?? false) !== true) !== [];
        // A normal removal checks each checkout's work is pushed with a fresh read token, read as a 0600 credential file.
        $git = [];
        if ($fetches) {
            app(InstanceGitHubReadTokens::class)->start($operation);
            $git = ['git_credential' => InstanceGitHubReadTokens::credentialReference($operation)];
        }
        $checked = $input['force'] === true ? [] : array_values(array_filter($members, static fn (array $member): bool => ($member['failed_creation'] ?? false) !== true));

        return ['intent' => [...$public, ...$native, 'source_capture' => $sourceCapture, 'teardown_steps' => $teardown,
            'push_check_paths' => array_map(static fn (array $member): string => ValidatedData::string($member['checkout_path']), $checked),
            'source_paths' => array_map(static fn (array $member): string => ValidatedData::string($member['checkout_path']), $members)],
            'execution_secrets' => [...$secrets, ...$serverCopies, ...$git]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
        if (($operation->input['container_destroy'] ?? null) !== null && $data['status'] === 'succeeded' && ! $operation->status->terminal()) {
            app(ContainerDestroyExecutionCopies::class)->remove($operation);
        }

        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->refresh();
            $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
            [$code, $result] = $this->outcome($data);
            $data = [...$data, 'error_code' => $code];
            if (! $operation->status->terminal()) {
                if ($data['status'] === 'succeeded') {
                    app(ProjectDirectInstanceDestroyAction::class)->execute(app(InstanceDestroySourceInspection::class)->executable($operation->input), $result);
                } elseif (in_array($data['status'], ['failed', 'cancelled'], true)) {
                    InstanceRemoval::query()->whereKey($operation->input['removal_id'])->update(['status' => InstanceRemovalStatus::Failed,
                        'error_code' => $data['error_code'] ?? 'operation.cancelled', 'current_step' => $this->failedStep($result), 'failed_step' => $this->failedStep($result)]);
                }
            }

            return $this->direct->project($operation, $data, $result);
        });
    }

    /** The public result from Swamp's run data: the first failed step names the phase; a forEach item's index suffix is dropped.
     * @param  array<string,mixed>  $data
     * @return array{0:?string,1:array<string,mixed>}
     */
    private function outcome(array $data): array
    {
        if (($data['status'] ?? null) === 'succeeded') {
            return [null, ['removed' => true]];
        }
        if (! in_array($data['status'] ?? null, ['failed', 'interrupted'], true)) {
            return [ValidatedData::nullableString($data['error_code'] ?? null), []];
        }
        $run = ValidatedData::object($data['result'] ?? []);
        $withdrawal = $run['withdrawal'] ?? null;
        if (is_array($withdrawal) && ($withdrawal['ready'] ?? null) === false) {
            return ['route.withdrawal_failed', ['removed' => false, 'failed_step' => 'Withdraw the Route', 'error_code' => 'route.withdrawal_failed']];
        }
        $summary = is_array($run['summary'] ?? null) ? $run['summary'] : [];
        $first = is_array($summary['failed_steps'] ?? null) && is_string($summary['failed_steps'][0] ?? null) ? $summary['failed_steps'][0] : null;
        $label = $first === null ? null : preg_replace('/-[0-9]+\z/', '', $first);
        $steps = is_array($run['steps'] ?? null) ? $run['steps'] : [];
        $failed = $first !== null && is_array($steps[$first] ?? null) ? $steps[$first] : [];
        $exit = is_int($failed['exit_code'] ?? null) ? $failed['exit_code'] : null;
        $code = match ($label) {
            'Check work is pushed', 'Remove the source' => 'instance.remove_refused',
            'Run teardown steps' => match ($exit) {
                124 => 'command.deadline_exceeded',
                127 => 'instance.teardown_step_unavailable',
                default => 'instance.teardown_step_failed',
            },
            default => 'instance.removal_incomplete',
        };

        return [$code, ['removed' => false, 'failed_step' => $label, 'error_code' => $code]];
    }

    /** @param array<string,mixed> $result */
    private function failedStep(array $result): InstanceRemovalStep
    {
        $step = $result['failed_step'] ?? '';
        if (! is_string($step)) {
            return InstanceRemovalStep::SourcePreparation;
        }

        return match ($step) {
            'Remove the source', 'Check it is gone' => InstanceRemovalStep::SourceFinalization,
            'Withdraw the Route' => InstanceRemovalStep::RouteTargetClear,
            'Stop, remove Processes', 'Run teardown steps', 'Remove SQLite files', 'Drop owned databases' => InstanceRemovalStep::RuntimeCleanup,
            default => InstanceRemovalStep::SourcePreparation,
        };
    }
}
