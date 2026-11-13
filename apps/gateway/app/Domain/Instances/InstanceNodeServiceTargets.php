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
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceNodeServiceTarget;
use App\Models\Node;
use App\Models\Process;
use App\Support\ValidatedData;

/** Explicit TCP endpoints; never inferred from an Instance's environment. */
final class InstanceNodeServiceTargets
{
    public static function assertValid(InstanceNodeServiceTarget $target, Instance $instance, bool $requireRunning = true): void
    {
        $process = Process::query()->find($target->process_id);
        if ($target->node_id !== $process?->owner_id) {
            throw new ResourceOperationException('instance.node_service_remote_node', 'The recorded service Node must still own the Process.', 422);
        }
        self::resolvePort($instance, $process, $target->protocol, $target->port, $requireRunning);
    }

    public static function resolvePort(Instance $instance, ?Process $process, string $protocol, ?int $requested, bool $requireRunning = true): int
    {
        if (! $process instanceof Process || $process->owner_type !== Node::class) {
            throw new ResourceOperationException('instance.node_service_process_missing', 'Select a registered Orbit Node Process.', 422);
        }
        self::assertStandalone($process);
        if ($protocol !== 'tcp') {
            throw new ResourceOperationException('instance.node_service_protocol_unsupported', 'A Node service target supports TCP only.', 422);
        }
        if ($requireRunning && $process->desired_state !== DesiredProcessState::Running) {
            throw new ResourceOperationException('instance.node_service_process_stopped', 'Start the registered Node Process before attaching or migrating its service.', 422);
        }
        $mappings = ! $requireRunning || $process->status === LifecycleStatus::Active ? self::publications($process) : [];
        if ($requested === null && count($mappings) > 1) {
            throw new ResourceOperationException('instance.node_service_port_required', 'This Process publishes multiple TCP ports. Select one explicitly.', 422);
        }
        $port = $requested ?? array_key_first($mappings);
        if ($port === null || ! isset($mappings[$port])) {
            throw new ResourceOperationException('instance.node_service_port_unpublished', 'The Process does not publish the selected TCP port.', 422);
        }
        $node = Node::query()->findOrFail($process->owner_id);
        self::assertPortAllowed($node, $port);
        if ($node->id !== $instance->node_id) {
            self::bridge($instance->node);
            $host = $node->wireguard_ip;
            if (! is_string($host) || ! array_any(['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
                static fn (string $cidr): bool => Ipv4Subnet::from($cidr)->containsUsableAddress($host))
                || count($mappings[$port]) !== 1 || $mappings[$port][0]->bindAddress !== $host) {
                throw new ResourceOperationException('instance.node_service_listener_private',
                    'A remote target needs one exact TCP publication on its Node private IPv4 WireGuard address.', 422);
            }

            return $port;
        }
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
            $targets[] = ['id' => $target->id, 'process_id' => $target->process_id, 'process_name' => $target->process->name, 'host' => $target->node_id === $instance->node_id ? self::bridge($instance->node) : ValidatedData::string($target->node->wireguard_ip), 'port' => $target->port,
                'docker' => $target->node_id === $instance->node_id, 'host_env_key' => $target->host_env_key, 'port_env_key' => $target->port_env_key];
        }

        return $targets;
    }

    /**
     * A clone reaches the Node services of its default: it takes a copy of each of the default's records, once, before its intent.
     * The copy holds the endpoint and the key names only, so the clone reaches no endpoint that its default cannot.
     */
    public static function inherit(Instance $clone, Instance $source): void
    {
        foreach ($source->nodeServiceTargets()->orderBy('id')->get() as $target) {
            $clone->nodeServiceTargets()->firstOrCreate(['host_env_key' => $target->host_env_key, 'port_env_key' => $target->port_env_key],
                $target->only(['process_id', 'node_id', 'protocol', 'port']));
        }
    }

    /**
     * The `.env` values of the recorded targets: each host and port under its own key names. A same-Node target is the container's
     * loopback proxy on the same port; a remote target is the service Node's WireGuard address. No credential is in it.
     *
     * @return array<string, string>
     */
    public static function environment(Instance $instance): array
    {
        $values = [];
        foreach ($instance->nodeServiceTargets()->with('node')->orderBy('id')->get() as $target) {
            $values[$target->host_env_key] = $target->node_id === $instance->node_id ? '127.0.0.1' : ValidatedData::string($target->node->wireguard_ip);
            $values[$target->port_env_key] = (string) $target->port;
        }

        return $values;
    }

    /** Managed proxy bypass; the network policy still admits only the selected TCP ports.
     * @param  list<array{host:string,docker:bool}>  $endpoints
     * @return array{NO_PROXY:string,no_proxy:string}
     */
    public static function proxyEnvironment(string $gateway, array $endpoints): array
    {
        $base = InstanceEgress::environment($gateway)['NO_PROXY'];
        $hosts = array_values(array_unique(array_column(array_filter($endpoints, static fn (array $target): bool => ! $target['docker']), 'host')));
        $value = $base.($hosts === [] ? '' : ','.implode(',', $hosts));

        return ['NO_PROXY' => $value, 'no_proxy' => $value];
    }

    /** An attached TCP publication cannot be released while any Instance depends on it. */
    public static function assertEndpointPreserved(Process $process, ?Process $candidate = null): void
    {
        if ($candidate === null && $process->desired_state !== DesiredProcessState::Running) {
            return;
        }
        $before = self::publications($process);
        $after = $candidate === null ? [] : self::publications($candidate);
        $instances = [];
        foreach (InstanceNodeServiceTarget::query()->where('process_id', $process->id)->with('instance.project')->orderBy('instance_id')->get() as $target) {
            $identity = static function (array $publications): array {
                $values = array_map(static fn (DockerPublishedPort $mapping): string => ($mapping->bindAddress ?? '0.0.0.0').':'.$mapping->publishedPort.':'.$mapping->containerPort, $publications);
                sort($values);

                return $values;
            };
            if ($candidate !== null && $identity($before[$target->port] ?? []) === $identity($after[$target->port] ?? [])) {
                continue;
            }
            $instance = $target->instance;
            $instances[$instance->id] = "Instance [{$instance->project->slug}/{$instance->name}] (id {$instance->id})";
        }
        if ($instances !== []) {
            throw new ResourceOperationException('process.node_service_endpoint_in_use',
                "Process [{$process->name}] is attached to ".implode(', ', $instances).
                '. Detach those Node service targets before stopping the Process or changing their TCP publication.', 409);
        }
    }

    /** @return array<int,list<DockerPublishedPort>> */
    private static function publications(Process $process): array
    {
        $mappings = [];
        $specs = $process->runtime_config['ports'] ?? [];
        if ($process->runtime === ProcessRuntime::Docker && is_array($specs)) {
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

        return $mappings;
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
        $reserved = DatabaseServer::query()->where('node_id', $node->id)->where('port', $port)->exists()
            || in_array($port, $fixed, true) || array_any(InstanceDevPorts::infrastructurePorts($node),
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
