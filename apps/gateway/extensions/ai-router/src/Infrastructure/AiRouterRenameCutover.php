<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Domain\AiRouterCache;
use RuntimeException;

/** One-time removal inputs, never runtime aliases. Intent remains until every external step succeeds. */
final readonly class AiRouterRenameCutover
{
    public const string RetiredSlug = 'proxycli';

    public const string RetiredHostname = 'collector.cli-proxy-api.orbit';

    public function __construct(
        private ProcessRuntimeManager $processes,
        private AiRouterCache $cache,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $hosts,
    ) {}

    public function assertComplete(): void
    {
        if (DB::table('ai_router_cutovers')->exists()) {
            throw new ResourceOperationException('ai_router.cutover_pending', 'The release cutover is pending. The next extension tick will retry it.', 409);
        }
    }

    public function pending(Process $process): bool
    {
        return DB::table('ai_router_cutovers')->where('process_id', $process->id)->exists();
    }

    public function retire(Process $process): void
    {
        $name = DB::table('ai_router_cutovers')->where('process_id', $process->id)->value('previous_name');
        if ($name !== 'cli-proxy-api-collector') {
            throw new RuntimeException('The AI router cutover does not identify the managed collector.');
        }
        $previous = clone $process;
        $previous->name = $name;
        $previous->runtime = ProcessRuntime::Systemd;
        // Runtime removal retains the original Process ID and checks the unit's Orbit ownership under its lease.
        $this->processes->remove($previous);
        $this->cache->forgetPrefix('orbit:'.self::RetiredSlug.':');
    }

    public function complete(Process $process): void
    {
        $node = Node::query()->findOrFail($process->owner_id);
        if (! is_string($node->wireguard_ip)) {
            throw new RuntimeException('The collector Node has no WireGuard address.');
        }
        $result = $this->ssh->execute(
            new SshConnection($node->wireguard_ip, $node->user, 22, $this->keys->privateKeyPath(), $this->hosts->path()),
            new RemoteCommand(arguments: ['sudo', 'rm', '-rf', '--', '/var/lib/orbit/'.self::RetiredSlug, '/etc/caddy/orbit-'.self::RetiredSlug.'-cert-current']),
        );
        if (! $result->succeeded()) {
            throw new RuntimeException('Could not remove the old collector files after cutover.');
        }
        DB::table('ai_router_cutovers')->where('process_id', $process->id)->delete();
    }
}
