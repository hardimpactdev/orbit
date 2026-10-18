<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Domain\ProxyCli\ProxyCliProcess;
use App\Domain\ProxyCli\ProxyCliRuntimeLifecycle;
use App\Models\Node;
use App\Models\Process;
use SensitiveParameter;

final class RecordingProxyCliRuntimeLifecycle implements ProxyCliRuntimeLifecycle
{
    public bool $converged = false;

    public bool $removed = false;

    /** @var array<string, string>|null */
    public ?array $environment = null;

    public function converge(
        Node $node,
        #[SensitiveParameter]
        array $environment,
    ): void {
        $data = ProxyCliProcess::data($node, $environment);
        $this->converged = true;
        $this->environment = $data->environment;
        Process::query()->updateOrCreate(
            [
                'owner_type' => Node::class,
                'owner_id' => $node->id,
                'name' => ProxyCliProcess::NAME,
            ],
            [
                'runtime' => $data->runtime,
                'working_directory' => $data->workingDirectory,
                'runtime_config' => [
                    'command' => $data->command,
                    'environment' => $data->environment,
                    'ports' => $data->ports,
                ],
                'restart_policy' => $data->restartPolicy,
                'desired_state' => 'running',
                'status' => 'active',
            ],
        );
    }

    public function remove(Node $node): void
    {
        $this->removed = true;
        Process::query()
            ->where('owner_type', Node::class)
            ->where('owner_id', $node->id)
            ->where('name', ProxyCliProcess::NAME)
            ->delete();
    }
}
