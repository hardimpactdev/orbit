<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Instances\InstancePathProxyData;
use App\Data\Instances\SetInstancePathProxyData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Instances\PathProxy;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Routes\AppRouteCaddyPublisher;
use App\Models\Instance;
use App\Models\InstancePathProxy;
use App\Models\Process;

final readonly class SetInstancePathProxyAction
{
    public function __construct(
        private ProcessRuntimeLease $processes,
        private DevelopmentProjectionOperationLock $projections,
        private PathProxy $paths,
        private AppRouteCaddyPublisher $publisher,
    ) {}

    public function execute(Instance $instance, SetInstancePathProxyData $data): InstancePathProxyData
    {
        return $this->processes->run(Process::query()->findOrFail($data->processId), fn (Process $process): InstancePathProxyData => $this->projections->run(function () use ($instance, $process, $data): InstancePathProxyData {
            PathProxy::upstream($process, $data->port);
            $route = $this->paths->select($instance->refresh(), $data->prefix);
            $proxy = InstancePathProxy::query()->updateOrCreate(['instance_id' => $instance->id], [
                'route_id' => $route->id, 'process_id' => $process->id, 'port' => $data->port, 'prefix' => $data->prefix, 'enabled' => true,
            ]);
            try {
                $this->publisher->publish($route);
            } catch (NodeCaddyBuildException $exception) {
                throw new ResourceOperationException('path_proxy.publication_failed', 'The path proxy intent is stored, but Caddy publication failed. Repeat set or run node:converge --force on its serving Nodes.', 502, previous: $exception);
            }

            return InstancePathProxyData::fromModel($proxy);
        }));
    }
}
