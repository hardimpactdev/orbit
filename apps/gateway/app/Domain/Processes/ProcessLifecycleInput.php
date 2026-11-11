<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Routes\NativeCaddyPublicationInput;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Public direct lifecycle intent and environment-only native execution copies. */
final readonly class ProcessLifecycleInput
{
    public function __construct(private ProcessOperationSecrets $secrets, private SwampCommandStep $steps) {}

    /** @return array{intent:array<string,mixed>,execution_secrets:array<string,array{vault:string,key:string}>} */
    public function forOperation(Operation $operation): array
    {
        return $this->build($operation, str_replace('.', '-', $operation->kind).'-operation-'.$operation->id);
    }

    /** @param array{id:int,input:array<string,mixed>} $member
     * @return array{kind:string,intent:array<string,mixed>,execution_secrets:array<string,array{vault:string,key:string}>}
     */
    public function forInstanceMember(Operation $parent, array $member): array
    {
        $process = Process::query()->findOrFail($member['id']);
        // tasks.provision targets its task group and runs on the VM host; its workspace Instance is on the guest.
        $workspace = $parent->kind === 'tasks.provision' && $parent->target_type === 'task_group';
        $instance = $workspace
            ? Instance::query()->whereKey($parent->input['instance_id'] ?? null)->where('task_workspace_owner_id', $parent->target_id)->first()
            : Instance::query()->whereKey($parent->target_id)->where('node_id', $parent->node_id)->first();
        if (! in_array($parent->kind, ['process.install_instance_definitions', 'instance.create', 'instance.clone', 'instance.register', 'tasks.provision'], true)
            || (! $workspace && ($parent->target_type !== 'instance' || $parent->target_id !== ($parent->input['instance_id'] ?? null)))
            || $instance === null || ! Instance::isMorphType($process->owner_type) || $process->owner_id !== $instance->id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The runtime member belongs to another owner.', 422);
        }

        $view = ProcessInstanceOperation::member($parent, $member, $instance->node_id);

        return ['kind' => $view->kind, ...$this->build($view, 'process-instance-operation-'.$parent->id.'-member-'.$member['id'])];
    }

    /** @return array{intent:array<string,mixed>,execution_secrets:array<string,array{vault:string,key:string}>} */
    private function build(Operation $operation, string $environmentKey): array
    {
        $input = $operation->input;
        $node = Node::query()->findOrFail($operation->node_id);
        if ($operation->target_type !== 'process' || $operation->target_id !== ($input['process_id'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Process intent does not match its target.', 422);
        }
        $material = in_array($operation->kind, ['process.create', 'process.update'], true)
            ? ValidatedData::object($this->secrets->resolve($operation, $operation->target_id, '', 'installation')->value) : null;

        return $this->forRenderedInstallation($input, $material, $node, $environmentKey);
    }

    /** The owning parent validates its frozen placement before rendering these bytes.
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>|null  $material
     * @return array{intent:array<string,mixed>,execution_secrets:array<string,array{vault:string,key:string}>}
     */
    public function forRenderedInstallation(array $input, ?array $material, Node $node, string $environmentKey): array
    {
        $processId = ValidatedData::integer($input['process_id']);
        $control = Arr::only($input, ['process_id', 'runtime', 'name', 'image', 'on_demand', 'directory', 'vite']);
        $intent = ['control' => $control, 'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($node)];
        if (isset($input['container'])) {
            $intent['container'] = $input['container'];
        }
        $copies = [];
        if ($material !== null) {
            $installation = Arr::only($input, ['process_id', 'runtime', 'name', 'desired', 'on_demand', 'directory', 'content_hash', 'activation_pending', 'spec', 'account_user', 'required_mount_paths']);
            $definition = $input['runtime'] === 'docker' ? ValidatedData::object($material['definition']) : [];
            $environment = $input['runtime'] === 'systemd'
                ? ValidatedData::string($material['environment'])
                : $this->dockerEnvironment(ValidatedData::object((array) Arr::pull($definition, 'environment')));
            $installation['retired'] = isset($input['retired_runtime'])
                ? ['runtime' => $input['retired_runtime'], 'name' => $input['retired_name']] : null;
            if ($input['runtime'] === 'systemd') {
                $installation['unit'] = $material['content'];
                $installation['has_environment'] = $environment !== '# Orbit Process '.$processId."\n";
            } else {
                $installation['definition'] = $definition;
                $installation['has_environment'] = $environment !== '';
            }
            $installation['environment_path'] = '/etc/orbit/processes/'.$processId.'/'.hash('sha256', $environment).'.env';
            $intent['installation'] = $installation;
            $paths = ValidatedData::stringList($installation['required_mount_paths'] ?? []);
            $intent['mounts'] = [];
            foreach ($paths === [] ? ['/'] : $paths as $index => $path) {
                $intent['mounts'][] = ['index' => $index, 'path' => $path];
            }
            if ($installation['has_environment']) {
                $copies['environment'] = $this->steps->executionCopy('orbit-control-inputs', $environmentKey, $environment);
            }
        }
        if (isset($input['vite_plan'])) {
            $intent['vite_plan'] = $input['vite_plan'];
            $intent['caddy_publication'] = app(NativeCaddyPublicationInput::class)->forNode($node);
        }

        return ['intent' => $intent, 'execution_secrets' => $copies];
    }

    /** Docker env files preserve single-line values without shell expansion.
     * @param  array<string, mixed>  $environment
     */
    public function dockerEnvironment(array $environment): string
    {
        $lines = [];
        foreach ($environment as $name => $value) {
            $lines[] = $name.'='.ValidatedData::string($value);
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }
}
