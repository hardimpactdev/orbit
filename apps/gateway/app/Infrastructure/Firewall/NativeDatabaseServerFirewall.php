<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

use App\Domain\DatabaseServers\DatabaseServerFirewall;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

final readonly class NativeDatabaseServerFirewall implements DatabaseServerFirewall
{
    public function __construct(private SshExecutor $ssh, private SshKeyProvider $keys, private KnownHostsStore $hosts) {}

    public function reconcile(Node $node, array $rules, bool $expand = false): void
    {
        if (! $this->run($node, $rules, 'reconcile', $expand)) {
            throw new ResourceOperationException('database.server_firewall_failed', 'The managed database ingress filter could not be verified. UFW must be active.', 502);
        }
    }

    public function matches(Node $node, array $rules): bool
    {
        return $this->run($node, $rules, 'inspect', false);
    }

    /** @param list<string> $rules */
    private function run(Node $node, array $rules, string $action, bool $expand): bool
    {
        $script = file_get_contents(__DIR__.'/database_server_firewall.py');
        if (! is_string($script)) {
            throw new ResourceOperationException('database.server_firewall_failed', 'The database filter program is unavailable.', 502);
        }
        $result = $this->ssh->execute(
            new SshConnection(host: (string) $node->wireguard_ip, user: $node->user, port: 22, identityFile: $this->keys->privateKeyPath(), knownHostsFile: $this->hosts->path()),
            new RemoteCommand(['sudo', 'python3', '-c', $script], input: json_encode(['action' => $action, 'rules' => $rules, 'expand' => $expand], JSON_THROW_ON_ERROR), timeout: 60),
        );

        return $result->succeeded() && trim($result->stdout) === 'matching';
    }
}
