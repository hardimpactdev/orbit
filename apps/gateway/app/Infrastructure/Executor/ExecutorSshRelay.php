<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Operation;

final readonly class ExecutorSshRelay
{
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private ExecutorResults $results,
    ) {}

    public function send(Operation $operation, #[\SensitiveParameter] string $wire, float $timeout): void
    {
        if ($operation->node_id === null) {
            return;
        }
        $node = Node::query()->findOrFail($operation->node_id);
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            return;
        }
        $result = $this->ssh->execute(new SshConnection($node->wireguard_ip, $node->user, 22, $this->keys->privateKeyPath(), $this->knownHosts->path()),
            new RemoteCommand(['sudo', '-n', '-u', 'orbit-executor-relay', '/usr/local/libexec/orbit-executor', 'relay'],
                protectedInput: ProtectedInput::fromString($wire."\n"), maxOutputBytes: 65536, timeout: $timeout, terminateGraceSeconds: 0.0));
        if ($result->truncated) {
            return;
        }
        foreach (explode("\n", $result->stdout) as $line) {
            if (($reply = ExecutorReply::fromEvent(['operation' => (string) $operation->id, 'line' => $line])) !== null) {
                $this->results->receive($node->id, $reply, 'ssh');
            }
        }
    }
}
