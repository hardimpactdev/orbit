<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Operations\OperationTransport;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class TaskVmImagePhaseCommand implements OperationTransport
{
    use TaskVmTransportSchema;

    public function __construct(private TaskVmImageRead $reads) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        $build = $this->reads->build($operation);
        if ($operation->kind === 'task_vm.image_intent') {
            return ['ready' => true];
        }
        $key = ValidatedData::object($input['key']);
        if (($key['identity'] ?? null) !== $build->recipe_identity || ($key['profile'] ?? null) !== 'base'
            || ($key['execution'] ?? null) !== (string) $build->id || ($key['phase'] ?? null) !== ($operation->input['phase'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The phase does not match this image attempt.', 422);
        }
        if ($operation->kind === 'task_vm.image_phase_intent') {
            return ['ready' => true];
        }
        $state = $this->reads->phase($operation, ValidatedData::string($key['phase']), ValidatedData::string($input['boot_id']));
        if (($state['state'] ?? null) !== 'complete') {
            throw new ResourceOperationException('task_vm.image_channel_lost', 'The phase has no proven completion.', 409);
        }

        return ['ready' => true, 'boot_id' => $state['boot_id']];
    }
}
