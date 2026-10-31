<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Instance;
use App\Models\ProjectDevelopmentDeployStep;
use App\Support\ValidatedData;

/** Material is copied once through the shared boundary; native steps receive names only. */
final readonly class InstanceDevelopmentWorkflowInput
{
    public function __construct(private SwampCommandStep $commands, private RepositoryReadAccess $repositories) {}

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function capture(Instance $instance, array $input): array
    {
        $deployment = ValidatedData::object($input['deployment']);
        $input['commit'] ??= '';
        if ($deployment['variant'] !== 'development') {
            return $input;
        }
        $programs = ProjectDevelopmentDeployStep::query()->where('project_id', ValidatedData::integer($deployment['project_id']))->get()->keyBy('name');
        $steps = [];
        $references = [];
        $admitted = $deployment['steps'];
        if (! is_array($admitted) || ! array_is_list($admitted)) {
            throw new ResourceOperationException('instance.step_not_admitted', 'The development command list is invalid.', 409);
        }
        foreach ($admitted as $index => $raw) {
            $step = ValidatedData::object($raw);
            $name = ValidatedData::string($step['name']);
            $row = $programs->get($name);
            if ($row === null || hash('sha256', $row->command) !== $step['command_digest']) {
                throw new ResourceOperationException('instance.step_not_admitted', 'The admitted development command changed before dispatch.', 409);
            }
            $steps[] = [...$step, 'step_name' => 'application-'.$index, 'order' => $index,
                'program_field' => 'command_'.$index];
            $references['command_'.$index] = $this->copy($instance, 'command-'.$name, $row->command);
        }
        $git = $this->repositories->for($instance->project->repository_url, $instance->project->source_access);

        return [...$input, 'deployment' => [...$deployment, 'steps' => $steps],
            'execution_secrets' => [...$references, 'git_read' => $this->copy($instance, 'git-read', json_encode(['github_token' => $git->githubToken()], JSON_THROW_ON_ERROR))]];
    }

    /** @return array{vault:string,key:string} */
    private function copy(Instance $instance, string $field, string $value): array
    {
        return $this->commands->executionCopy('orbit-control-inputs', 'instance-'.$instance->id.'-'.$field.'-'.hash('sha256', $value), $value);
    }
}
