<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Models\Node;
use App\Services\RemoteShell\Exceptions\HostKeyMissing;
use Illuminate\Support\Facades\File;

final class SshCommandBuilder
{
    /**
     * @param  array{
     *     batch_mode?: bool,
     *     connect_timeout?: int,
     *     strict_host_key_checking?: string,
     *     log_level?: string,
     *     server_alive_interval?: int,
     *     server_alive_count_max?: int,
     *     user_known_hosts_file?: string,
     *     global_known_hosts_file?: string,
     *     update_host_keys?: bool,
     * }  $options
     */
    public function ssh(string $user, string $host, string $remoteCommand, array $options = []): string
    {
        return implode(' ', [
            'ssh',
            ...$this->sshOptions($options),
            escapeshellarg($user).'@'.escapeshellarg($host),
            escapeshellarg($remoteCommand),
        ]);
    }

    /**
     * @param  array{
     *     batch_mode?: bool,
     *     connect_timeout?: int,
     *     strict_host_key_checking?: string,
     *     log_level?: string,
     *     server_alive_interval?: int,
     *     server_alive_count_max?: int,
     *     user_known_hosts_file?: string,
     *     global_known_hosts_file?: string,
     *     update_host_keys?: bool,
     * }  $options
     */
    public function sshForNode(Node $node, string $remoteCommand, ?string $loginUser = null, array $options = []): string
    {
        return $this->ssh(
            user: $loginUser ?: ($node->user ?: 'orbit'),
            host: $this->hostForNode($node, (bool) ($options['prefer_public_host'] ?? false)),
            remoteCommand: $remoteCommand,
            options: $options,
        );
    }

    /**
     * @param  array{
     *     batch_mode?: bool,
     *     connect_timeout?: int,
     *     log_level?: string,
     *     server_alive_interval?: int,
     *     server_alive_count_max?: int,
     *     prefer_public_host?: bool,
     * }  $options
     */
    public function enforceForNode(Node $node, string $remoteCommand, ?string $loginUser = null, array $options = []): string
    {
        $knownHostsFile = $this->knownHostsFileForNode($node);

        return $this->sshForNode(
            node: $node,
            remoteCommand: $remoteCommand,
            loginUser: $loginUser,
            options: [
                ...$options,
                'strict_host_key_checking' => 'yes',
                'user_known_hosts_file' => $knownHostsFile,
                'global_known_hosts_file' => '/dev/null',
                'update_host_keys' => false,
            ],
        );
    }

    /**
     * @param  array{
     *     batch_mode?: bool,
     *     connect_timeout?: int,
     *     strict_host_key_checking?: string,
     *     user_known_hosts_file?: string,
     *     global_known_hosts_file?: string,
     *     update_host_keys?: bool,
     * }  $options
     */
    public function scpTo(string $source, string $user, string $host, string $destination, array $options = []): string
    {
        return implode(' ', [
            'scp',
            ...$this->scpOptions($options),
            escapeshellarg($source),
            escapeshellarg($user).'@'.escapeshellarg($host).':'.escapeshellarg($destination),
        ]);
    }

    /**
     * @param  array{
     *     batch_mode?: bool,
     *     connect_timeout?: int,
     *     strict_host_key_checking?: string,
     *     user_known_hosts_file?: string,
     *     global_known_hosts_file?: string,
     *     update_host_keys?: bool,
     * }  $options
     */
    public function scpToNode(Node $node, string $source, string $destination, ?string $loginUser = null, array $options = []): string
    {
        $host = $this->hostForNode($node, (bool) ($options['prefer_public_host'] ?? false));
        $user = $loginUser ?: ($node->user ?: 'orbit');

        if (($options['strict_host_key_checking'] ?? null) === 'yes') {
            $options = [
                ...$options,
                'user_known_hosts_file' => $options['user_known_hosts_file'] ?? $this->knownHostsFileForNode($node),
                'global_known_hosts_file' => $options['global_known_hosts_file'] ?? '/dev/null',
                'update_host_keys' => $options['update_host_keys'] ?? false,
            ];
        }

        return $this->scpTo($source, $user, $host, $destination, $options);
    }

    public function hostForNode(Node $node, bool $preferPublicHost = false): string
    {
        if ($preferPublicHost && filled($node->host)) {
            return $node->host;
        }

        if ($this->dockerTopologyMode() === 'dns-alias' && filled($node->host)) {
            return $node->host;
        }

        return $node->wireguard_address ?: $node->host;
    }

    /**
     * @param  array{
     *     batch_mode?: bool,
     *     connect_timeout?: int,
     *     strict_host_key_checking?: string,
     *     log_level?: string,
     *     server_alive_interval?: int,
     *     server_alive_count_max?: int,
     *     user_known_hosts_file?: string,
     *     global_known_hosts_file?: string,
     *     update_host_keys?: bool,
     * }  $options
     * @return list<string>
     */
    private function sshOptions(array $options): array
    {
        $resolved = [];

        if (($options['batch_mode'] ?? false) === true) {
            $resolved[] = '-o BatchMode=yes';
        }

        $resolved[] = '-o StrictHostKeyChecking='.($options['strict_host_key_checking'] ?? 'accept-new');

        if (isset($options['user_known_hosts_file'])) {
            $resolved[] = '-o UserKnownHostsFile='.escapeshellarg((string) $options['user_known_hosts_file']);
        }

        if (isset($options['global_known_hosts_file'])) {
            $resolved[] = '-o GlobalKnownHostsFile='.escapeshellarg((string) $options['global_known_hosts_file']);
        }

        if (($options['update_host_keys'] ?? null) === false) {
            $resolved[] = '-o UpdateHostKeys=no';
        }

        if (isset($options['log_level'])) {
            $resolved[] = '-o LogLevel='.$options['log_level'];
        }

        $resolved[] = '-o ConnectTimeout='.(int) ($options['connect_timeout'] ?? 10);

        if (isset($options['server_alive_interval'])) {
            $resolved[] = '-o ServerAliveInterval='.(int) $options['server_alive_interval'];
        }

        if (isset($options['server_alive_count_max'])) {
            $resolved[] = '-o ServerAliveCountMax='.(int) $options['server_alive_count_max'];
        }

        return $resolved;
    }

    /**
     * @param  array{
     *     batch_mode?: bool,
     *     connect_timeout?: int,
     *     strict_host_key_checking?: string,
     *     user_known_hosts_file?: string,
     *     global_known_hosts_file?: string,
     *     update_host_keys?: bool,
     * }  $options
     * @return list<string>
     */
    private function scpOptions(array $options): array
    {
        $resolved = [];

        if (($options['batch_mode'] ?? false) === true) {
            $resolved[] = '-o BatchMode=yes';
        }

        $resolved[] = '-o StrictHostKeyChecking='.($options['strict_host_key_checking'] ?? 'accept-new');

        if (isset($options['user_known_hosts_file'])) {
            $resolved[] = '-o UserKnownHostsFile='.escapeshellarg((string) $options['user_known_hosts_file']);
        }

        if (isset($options['global_known_hosts_file'])) {
            $resolved[] = '-o GlobalKnownHostsFile='.escapeshellarg((string) $options['global_known_hosts_file']);
        }

        if (($options['update_host_keys'] ?? null) === false) {
            $resolved[] = '-o UpdateHostKeys=no';
        }

        $resolved[] = '-o ConnectTimeout='.(int) ($options['connect_timeout'] ?? 10);

        return $resolved;
    }

    private function knownHostsFileForNode(Node $node): string
    {
        if (
            ! is_string($node->host_key_type)
            || $node->host_key_type === ''
            || ! is_string($node->host_key_public)
            || $node->host_key_public === ''
            || ! is_string($node->host_key_fingerprint)
            || $node->host_key_fingerprint === ''
        ) {
            throw HostKeyMissing::forNode($node->name);
        }

        $path = storage_path("framework/ssh-known-hosts/node-{$node->getKey()}");
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->knownHostsContent($node));
        @chmod($path, 0600);

        return $path;
    }

    private function knownHostsContent(Node $node): string
    {
        $hosts = array_values(array_unique(array_filter([
            $node->host,
            $node->wireguard_address,
            $this->dockerTopologyMode() === 'dns-alias' ? $node->host : null,
        ], fn ($host): bool => is_string($host) && $host !== '')));

        return collect($hosts)
            ->map(fn (string $host): string => "{$host} {$node->host_key_type} {$node->host_key_public}")
            ->implode(PHP_EOL).PHP_EOL;
    }

    private function dockerTopologyMode(): ?string
    {
        $processValue = getenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE');

        if (is_string($processValue) && $processValue !== '') {
            return $processValue;
        }

        $serverValue = $_SERVER['ORBIT_E2E_DOCKER_TOPOLOGY_MODE'] ?? null;

        if (is_string($serverValue) && $serverValue !== '') {
            return $serverValue;
        }

        $envValue = $_ENV['ORBIT_E2E_DOCKER_TOPOLOGY_MODE'] ?? null;

        return is_string($envValue) && $envValue !== '' ? $envValue : null;
    }
}
