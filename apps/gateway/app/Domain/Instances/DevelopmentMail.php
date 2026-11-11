<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;

/** The Node-owned SMTP endpoint and its two development-only loopback proxies. */
final readonly class DevelopmentMail
{
    /** The main Mailpit exists only on the Node that owns its configured running Docker Process.
     * @return array{host:string,port:int,docker:bool}|null
     */
    public function endpoint(Node $node): ?array
    {
        $port = config('instances.mailpit.port');
        $name = config('instances.mailpit.process');
        if (! is_int($port) || $port < 1 || $port > 65535 || ! is_string($name) || ! is_string($node->instance_bridge)) {
            return null;
        }
        $process = Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->where('name', $name)
            ->where('runtime', ProcessRuntime::Docker)->where('status', LifecycleStatus::Active)->where('desired_state', DesiredProcessState::Running)->first();
        if ($process === null) {
            return null;
        }
        $gateway = Ipv4Subnet::from($node->instance_bridge)->usableRange()[0];
        $publications = $process->runtime_config['ports'] ?? [];
        if (! is_array($publications)) {
            return null;
        }
        foreach ($publications as $publication) {
            if (! is_string($publication) || (str_contains($publication, '/') && ! str_ends_with($publication, '/tcp'))) {
                continue;
            }
            $mapping = DockerPublishedPort::parse($publication);
            if ($mapping !== null && $mapping->publishedPort === $port && in_array($mapping->bindAddress, [null, '0.0.0.0', $gateway], true)) {
                return ['host' => $gateway, 'port' => $port, 'docker' => true];
            }
        }

        return null;
    }

    /** @return array{host:string,port:int,docker:bool}|null */
    public function forInstance(Instance $instance): ?array
    {
        return $instance->placementEnvironment() === 'development' && $instance->runtime()->exists()
            ? $this->endpoint($instance->node) : null;
    }

    /** @param array{host:string,port:int,docker:bool} $endpoint
     * @return list<array{name:string,content:string}>
     */
    public function units(array $endpoint): array
    {
        $files = [];
        foreach ([1025, 2525] as $port) {
            $name = 'orbit-mail-'.$port;
            $files[] = ['name' => $name.'.socket', 'content' => "# Managed by Orbit: development mail.\n[Unit]\nDescription=Orbit development SMTP {$port}\n[Socket]\nListenStream=127.0.0.1:{$port}\n[Install]\nWantedBy=sockets.target\n"];
            $files[] = ['name' => $name.'.service', 'content' => "# Managed by Orbit: development mail.\n[Unit]\nDescription=Orbit development SMTP proxy {$port}\nAfter=network.target\n[Service]\nExecStart=/usr/lib/systemd/systemd-socket-proxyd {$endpoint['host']}:{$endpoint['port']}\nRestart=on-failure\nNoNewPrivileges=yes\nProtectSystem=strict\nProtectHome=yes\nPrivateTmp=yes\n"];
        }

        return $files;
    }
}
