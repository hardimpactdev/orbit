<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\AppDev\VitePortAllocator;
use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\Firewall\FirewallPort;
use App\Domain\Firewall\FirewallProtectedPorts;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRemovalGuard;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Models\Instance;
use App\Models\InstanceNodeServiceTarget;
use App\Models\Node;
use App\Models\Process;
use App\Support\ValidatedData;

/** Explicit local TCP endpoints; never inferred from an Instance's environment. */
final class InstanceNodeServiceTargets
{
    public static function assertValid(InstanceNodeServiceTarget $target, Instance $instance, bool $requireRunning = true): void
    {
        if ($target->node_id !== $instance->node_id) {
            throw new ResourceOperationException('instance.node_service_remote_node', "A Node service target must be on the Instance's own Node.", 422);
        }
        $process = Process::query()->find($target->process_id);
        self::resolvePort($instance, $process, $target->protocol, $target->port, $requireRunning);
    }

    public static function resolvePort(Instance $instance, ?Process $process, string $protocol, ?int $requested, bool $requireRunning = true): int
    {
        if (! $process instanceof Process || $process->owner_type !== Node::class) {
            throw new ResourceOperationException('instance.node_service_process_missing', 'Select a registered Orbit Node Process.', 422);
        }
        if ($process->owner_id !== $instance->node_id) {
            throw new ResourceOperationException('instance.node_service_remote_node', "The Process must belong to the Instance's own Node.", 422);
        }
        self::assertStandalone($process);
        if ($protocol !== 'tcp') {
            throw new ResourceOperationException('instance.node_service_protocol_unsupported', 'A Node service target supports TCP only.', 422);
        }
        if ($requireRunning && $process->desired_state !== DesiredProcessState::Running) {
            throw new ResourceOperationException('instance.node_service_process_stopped', 'Start the registered Node Process before attaching or migrating its service.', 422);
        }
        $mappings = [];
        $specs = $process->runtime_config['ports'] ?? [];
        if ($process->runtime === ProcessRuntime::Docker && (! $requireRunning || $process->status === LifecycleStatus::Active) && is_array($specs)) {
            foreach ($specs as $spec) {
                if (! is_string($spec) || (str_contains($spec, '/') && ! str_ends_with($spec, '/tcp'))) {
                    continue;
                }
                $mapping = DockerPublishedPort::parse($spec);
                if ($mapping instanceof DockerPublishedPort) {
                    $mappings[$mapping->publishedPort][] = $mapping;
                }
            }
        }
        if ($requested === null && count($mappings) > 1) {
            throw new ResourceOperationException('instance.node_service_port_required', 'This Process publishes multiple TCP ports. Select one explicitly.', 422);
        }
        $port = $requested ?? array_key_first($mappings);
        if ($port === null || ! isset($mappings[$port])) {
            throw new ResourceOperationException('instance.node_service_port_unpublished', 'The Process does not publish the selected TCP port.', 422);
        }
        $node = Node::query()->findOrFail($instance->node_id);
        self::assertPortAllowed($node, $port);
        $bridge = self::bridge($node);
        if (! array_any($mappings[$port], static fn (DockerPublishedPort $mapping): bool => in_array($mapping->bindAddress, [null, '0.0.0.0', $bridge], true))) {
            throw new ResourceOperationException('instance.node_service_listener_private', 'The published port must bind to 0.0.0.0 or the Instance bridge address.', 422);
        }

        return $port;
    }

    /** Exact recorded attachments projected into public container intent. No environment values are read.
     * @return list<array{id:int,process_id:int,process_name:string,host:string,port:int,docker:bool,host_env_key:string,port_env_key:string}>
     */
    public static function endpoints(Instance $instance, bool $requireRunning = true): array
    {
        $targets = [];
        foreach ($instance->nodeServiceTargets()->with('process')->orderBy('id')->get() as $target) {
            self::assertValid($target, $instance, $requireRunning);
            $targets[] = ['id' => $target->id, 'process_id' => $target->process_id, 'process_name' => $target->process->name, 'host' => self::bridge($instance->node), 'port' => $target->port,
                'docker' => true, 'host_env_key' => $target->host_env_key, 'port_env_key' => $target->port_env_key];
        }

        return $targets;
    }

    private static function assertStandalone(Process $process): void
    {
        $guards = app()->tagged(ProcessRemovalGuard::class);
        foreach ($guards as $guard) {
            // Attachment dependencies do not confer service ownership.
            if ($guard instanceof InstanceNodeServiceProcessRemovalGuard) {
                continue;
            }
            try {
                $guard->assertRemovable($process);
            } catch (ResourceOperationException $exception) {
                throw new ResourceOperationException('instance.node_service_process_owned',
                    'Select a standalone Node Process. '.$exception->getMessage(), 422);
            }
        }
    }

    private static function assertPortAllowed(Node $node, int $port): void
    {
        $fixed = [22, 80, 443, InstanceDevPorts::Listener, InstanceHostIntent::T3Port, 2375, 2376, ...VitePortAllocator::EXCLUDED_PORTS,
            ValidatedData::integer(config('orbit.websocket.port')), ValidatedData::integer(config('orbit.pi.port'))];
        $reserved = in_array($port, $fixed, true) || array_any(InstanceDevPorts::infrastructurePorts($node),
            static fn (string $reserved): bool => FirewallPort::contains(str_replace('-', ':', $reserved), $port));
        try {
            app(FirewallProtectedPorts::class)->guard($node, (string) $port);
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode !== 'firewall.protected_port') {
                throw $exception;
            }
            $reserved = true;
        }
        if ($reserved) {
            throw new ResourceOperationException('instance.node_service_port_reserved', 'Orbit reserves this Node port for infrastructure; it cannot be attached as an application service.', 422);
        }
    }

    private static function bridge(Node $node): string
    {
        $bridge = $node->instance_bridge;
        if (! is_string($bridge)) {
            throw new ResourceOperationException('instance.host_unavailable', 'The Node has no Instance bridge yet: converge its app-dev role first.', 409);
        }

        return Ipv4Subnet::from($bridge)->usableRange()[0];
    }
}
