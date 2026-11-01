<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Routes\NativeCaddyPublicationInput;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Public direct lifecycle intent and environment-only native execution copies. */
final readonly class ProcessLifecycleInput
{
    public function __construct(private ProcessOperationSecrets $secrets, private SwampCommandStep $steps) {}

    /** @return array<string,mixed> */
    public function forOperation(Operation $operation): array
    {
        $input = $operation->input;
        $node = Node::query()->findOrFail($operation->node_id);
        if ($operation->target_type !== 'process' || $operation->target_id !== ($input['process_id'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Process intent does not match its target.', 422);
        }
        $control = Arr::only($input, ['process_id', 'runtime', 'name', 'on_demand', 'directory', 'vite']);
        $intent = ['control' => $control, 'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($node)];
        $copies = [];
        if (in_array($operation->kind, ['process.create', 'process.update'], true)) {
            $material = ValidatedData::object($this->secrets->resolve($operation, $operation->target_id, '', 'installation')->value);
            $installation = Arr::only($input, ['process_id', 'runtime', 'name', 'desired', 'on_demand', 'directory', 'content_hash', 'activation_pending', 'spec', 'account_user', 'required_mount_paths']);
            $definition = $input['runtime'] === 'docker' ? ValidatedData::object($material['definition']) : [];
            $environment = $input['runtime'] === 'systemd'
                ? ValidatedData::string($material['environment'])
                : json_encode(Arr::pull($definition, 'environment'), JSON_THROW_ON_ERROR);
            $installation['retired'] = isset($input['retired_runtime'])
                ? ['runtime' => $input['retired_runtime'], 'name' => $input['retired_name']] : null;
            if ($input['runtime'] === 'systemd') {
                $installation['unit'] = $material['content'];
                $installation['environment_path'] = '/etc/orbit/processes/'.$operation->target_id.'/'.hash('sha256', $environment).'.env';
                $installation['has_environment'] = $environment !== '# Orbit Process '.$operation->target_id."\n";
            } else {
                $installation['definition'] = $definition;
                $installation['has_environment'] = $environment !== '{}';
            }
            $intent['installation'] = $installation;
            $paths = ValidatedData::stringList($installation['required_mount_paths'] ?? []);
            $intent['mounts'] = [];
            foreach ($paths === [] ? ['/'] : $paths as $index => $path) {
                $intent['mounts'][] = ['index' => $index, 'path' => $path];
            }
            if ($installation['has_environment']) {
                $copies['environment'] = $this->steps->executionCopy('orbit-control-inputs', str_replace('.', '-', $operation->kind).'-operation-'.$operation->id, $environment);
            }
            if (isset($input['vite_plan'])) {
                $intent['vite_plan'] = $input['vite_plan'];
                $intent['caddy_publication'] = app(NativeCaddyPublicationInput::class)->forNode($node);
            }
        }

        return ['intent' => $intent, 'execution_secrets' => $copies];
    }
}
