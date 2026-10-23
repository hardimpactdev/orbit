<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilder;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildLock;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddyTransport;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;

/** Records fake agent publications in the same ordered event list as the publisher's other Node calls. */
final class SshNodeCaddyBuilds
{
    public static function over(SshExecutor $ssh): NodeCaddyBuilder
    {
        $agents = new FakeAgentCommandRunner()->answer('caddy.publish', static function (array $input, Node $node) use ($ssh): array {
            $result = $ssh->execute(
                new SshConnection(host: (string) $node->wireguard_ip, user: $node->user, port: 22, identityFile: '/tmp/orbit-test-key', knownHostsFile: '/tmp/orbit-test-known-hosts'),
                new RemoteCommand(arguments: ['orbit-agent', 'command', 'caddy.publish'], input: json_encode($input, JSON_THROW_ON_ERROR)),
            );
            if (! $result->succeeded()) {
                throw new AgentCommandRefused('caddy.publish', 'caddy.publication_failed', $result->stderr, ['stage' => 'reload', 'message' => $result->stderr]);
            }

            return ['result' => 'published'];
        });

        return new NodeCaddyBuilder(
            app(NodeCaddyfileRenderer::class),
            new NodeCaddyBuildLock(TestOrbitHome::scratch('caddy-build')),
            new NodeCaddyTransport($agents),
        );
    }

    public static function pushed(RemoteCommand $command): ?string
    {
        if ($command->arguments !== ['orbit-agent', 'command', 'caddy.publish']) {
            return null;
        }

        $input = json_decode((string) $command->input, true, flags: JSON_THROW_ON_ERROR);

        return $input['content'];
    }
}
