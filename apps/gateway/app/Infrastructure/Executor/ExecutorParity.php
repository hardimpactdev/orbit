<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeExecutorInput;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Throwable;

/**
 * Executor build parity (ADR 0266, "Must clear before the signed power flags go on"; docs/reference/executor.md#build-parity).
 * Every Node whose roles enable an executor family must run the build that this Gateway pins: the converge intent's SHA-256 of
 * artifact `executor-linux-x86_64`. Each Node reports the SHA-256 of its installed binary and the service state over its pinned
 * SSH account, with one fixed read-only argv. A changed binary restarts the service in the converge, so an active service runs
 * the installed binary.
 */
final readonly class ExecutorParity
{
    /** Prints the installed binary's SHA-256, then the service state; neither needs root. */
    private const string Read = 'sha256sum '.NodeExecutorInput::Binary.' 2>/dev/null | cut -d " " -f 1; systemctl is-active orbit-executor.service 2>/dev/null; true';

    public function __construct(
        private ManagedNodeEligibility $eligibility,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    /**
     * One row per executor Node, by Node id. `problem` is null when the Node runs the pinned build.
     *
     * @return list<array{node_id: int, node: string, expected: ?string, reported: ?string, state: ?string, problem: ?string}>
     */
    public function check(): array
    {
        $rows = [];
        $nodes = Node::query()->with('roles')->where('status', LifecycleStatus::Active)->where('platform', 'linux')->orderBy('id')->get();
        foreach ($nodes as $node) {
            $intent = NodeExecutorInput::for($node);
            if ($intent === null) {
                continue;
            }
            $expected = is_string($intent['sha256']) ? $intent['sha256'] : null;
            [$reported, $state, $readProblem] = $this->eligibility->allows($node) ? $this->read($node) : [null, null, 'executor.node_unmanaged'];
            $problem = match (true) {
                is_string($intent['skip']) => $intent['skip'],
                is_string($intent['plan_error']) => $intent['plan_error'],
                $readProblem !== null => $readProblem,
                $reported === null => 'executor.missing',
                $reported !== $expected => 'executor.build_differs',
                $state !== 'active' => 'executor.inactive',
                default => null,
            };
            $rows[] = ['node_id' => $node->id, 'node' => $node->name, 'expected' => $expected, 'reported' => $reported, 'state' => $state, 'problem' => $problem];
        }

        return $rows;
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private function read(Node $node): array
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            return [null, null, 'executor.node_unreachable'];
        }
        try {
            $result = $this->ssh->execute(new SshConnection($node->wireguard_ip, $node->user, 22, $this->keys->privateKeyPath(), $this->knownHosts->path()),
                new RemoteCommand(['sh', '-c', self::Read], maxOutputBytes: 1024, timeout: 15.0));
        } catch (Throwable) {
            return [null, null, 'executor.node_unreachable'];
        }
        if (! $result->succeeded() || $result->truncated) {
            return [null, null, 'executor.node_unreachable'];
        }
        $lines = explode("\n", rtrim($result->stdout));
        $sha = count($lines) === 2 && preg_match('/\A[0-9a-f]{64}\z/', $lines[0]) === 1 ? $lines[0] : null;
        $state = trim((string) end($lines));

        return [$sha, $state === '' ? null : $state, null];
    }
}
