<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;

/** The same rule is loaded by the Gateway renderer and the container's mail editor. */
final readonly class DevelopmentMailEnvironment
{
    public const array Keys = ['MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_ENCRYPTION', 'MAIL_SCHEME'];

    /** @param array<string,string> $values
     * @param  list<string>  $owned
     * @return array<string,string>
     */
    public static function resolve(array $values, string $host, int $port, array $owned = []): array
    {
        $desired = ['MAIL_MAILER' => 'smtp', 'MAIL_HOST' => $host, 'MAIL_PORT' => (string) $port,
            'MAIL_USERNAME' => '', 'MAIL_PASSWORD' => '', 'MAIL_ENCRYPTION' => '', 'MAIL_SCHEME' => ''];
        if (trim($values['MAIL_URL'] ?? '') !== '') {
            return [];
        }
        // Already owned defaults remain owned on an unchanged repeat, without treating an arbitrary custom host as stock.
        if (array_diff(self::Keys, $owned) === [] && array_diff_assoc($desired, $values) === []) {
            return $desired;
        }
        $mailer = strtolower(trim($values['MAIL_MAILER'] ?? ''));
        $mailHost = strtolower(trim($values['MAIL_HOST'] ?? ''));
        $mailPort = trim($values['MAIL_PORT'] ?? '');
        if (! in_array($mailer, ['', 'smtp', 'log'], true)
            || str_contains($mailHost, '${')
            || ! in_array($mailHost, ['', '127.0.0.1', 'localhost', '10.6.0.7'], true)) {
            return [];
        }
        if ($mailer !== 'log' && $mailHost !== '10.6.0.7' && ! in_array($mailPort, ['', '1025', '2525'], true)) {
            return [];
        }

        return $desired;
    }

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

    /** @param array<string,string> $values
     * @return array<string,string>
     */
    public function forInstance(Instance $instance, array $values): array
    {
        if ($instance->placementEnvironment() !== 'development' || ! $instance->runtime()->exists()) {
            return [];
        }
        $endpoint = $this->endpoint($instance->node);

        return $endpoint === null ? [] : self::resolve($values, $endpoint['host'], $endpoint['port']);
    }
}
