<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Models\Operation;
use App\Support\ValidatedData;
use InvalidArgumentException;
use JsonException;

/**
 * Projects the terminal run of a topology seed refresh. The Gateway keeps no state of its own for it: the seed lives on
 * the host under the name every claim already clones, and the succeeded Operation is the record of when it was
 * refreshed and what it holds. A run that failed changes nothing: the host still has the seed it had, because the run
 * promotes the new seed only as its last host change.
 */
final readonly class TaskVmSeedRefreshProjection implements DirectOperationProjection, NativeOperationProjection
{
    /** The end check: a native assert over the ready copies, the promotion and the host listing that follows it. */
    public const string EndCheck = 'Check the seed';

    /** The step that lists the ready copies in the seed, before it is stopped. */
    public const string ReadyStep = 'Make the ready copies';

    /** The step that swaps the seed and prints what the host holds afterwards. */
    public const string PromoteStep = 'Promote the seed';

    /** @return array<string,array{job:string,step:string}> */
    public static function captures(): array
    {
        return ['ready' => ['job' => 'build', 'step' => self::ReadyStep], 'promoted' => ['job' => 'promote', 'step' => self::PromoteStep]];
    }

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        $input = $operation->input;
        if ($operation->kind !== 'task_vm.seed_refresh' || $operation->target_type !== 'task_vm_host' || ($input['host_node_id'] ?? null) !== $operation->target_id
            || ($input['node_id'] ?? null) !== $operation->node_id || ! is_array($input['seed'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'A seed refresh needs its admitted host and seed.', 422);
        }

        return TaskVmProviderWorkflowInput::envelope($input);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->input($operation);
        if ($data['status'] === OperationStatus::Interrupted->value) {
            $data['status'] = OperationStatus::Failed->value;
        }
        $result = [];
        if (OperationStatus::from(ValidatedData::string($data['status'])) === OperationStatus::Succeeded) {
            $run = ValidatedData::object($data['result'] ?? []);
            if (NativeRunResult::stepStatus($run, self::EndCheck) !== 'succeeded') {
                throw new ResourceOperationException('operation.result_invalid', 'A succeeded seed refresh needs its passed end check.', 422);
            }
            $result = $this->report($operation, $run);
        }

        return app(DirectOperation::class)->project($operation, $data, $result);
    }

    /**
     * What the run printed, checked against the admitted intent: the seed the host holds is the one the claim path
     * clones, and its ready copies are the roles the intent named.
     *
     * @param  array<string,mixed>  $run
     * @return array{ready:true,host_node_id:int,image_fingerprint:string,seed:string,roles:list<string>,referenced_bytes:int,written_bytes:int}
     */
    private function report(Operation $operation, array $run): array
    {
        $input = ValidatedData::object($operation->input);
        $seed = ValidatedData::object($input['seed']);
        $roles = ValidatedData::stringList(ValidatedData::object($input['builder'])['roles']);
        try {
            $promoted = ValidatedData::object(json_decode(ValidatedData::string(ValidatedData::object($run['promoted'] ?? [])['stdout'] ?? null), true, flags: JSON_THROW_ON_ERROR));
            $ready = ValidatedData::stringList(json_decode(ValidatedData::string(ValidatedData::object($run['ready'] ?? [])['stdout'] ?? null), true, flags: JSON_THROW_ON_ERROR));
        } catch (JsonException|InvalidArgumentException) {
            throw $this->invalid();
        }
        $expected = array_map(static fn (string $role): string => "ready-{$role}", $roles);
        sort($expected);
        sort($ready);
        $bytes = $promoted['referenced_bytes'] ?? null;
        $written = $promoted['written_bytes'] ?? null;
        if ($promoted['seed'] !== $seed['current'] || $ready !== $expected
            || ! is_int($bytes) || $bytes < 1 || ! is_int($written) || $written < 0) {
            throw $this->invalid();
        }

        return ['ready' => true, 'host_node_id' => ValidatedData::integer($input['host_node_id']),
            'image_fingerprint' => ValidatedData::string(ValidatedData::object($input['image'])['fingerprint']),
            'seed' => ValidatedData::string($seed['current']), 'roles' => $roles, 'referenced_bytes' => $bytes, 'written_bytes' => $written];
    }

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('operation.result_invalid', 'A seed refresh needs the report of its promotion and its ready copies.', 422);
    }
}
