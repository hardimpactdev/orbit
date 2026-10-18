<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Fleet\NodeShell;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Domain\AiRouterCache;
use RuntimeException;

/** One-time removal inputs, never runtime aliases. Removed after live cutover in ADR 0212 step 4. */
final readonly class AiRouterRenameCutover
{
    public function __construct(
        private ProcessRuntimeLease $lease,
        private AiRouterCache $cache,
        private NodeShell $shell,
    ) {}

    public function assertComplete(): void
    {
        if ($this->pending()) {
            throw new ResourceOperationException('ai_router.cutover_pending', 'The release cutover is pending. The next extension tick will retry it.', 409);
        }
    }

    public function pending(?Process $process = null): bool
    {
        $query = DB::table('ai_router_cutovers');

        return ($process === null ? $query : $query->where('process_id', $process->id))->exists();
    }

    public function retire(Process $process): void
    {
        $this->lease->run($process, function (Process $fresh): void {
            $node = Node::query()->findOrFail($fresh->owner_id);
            $id = $fresh->id;
            $script = <<<BASH
                unit=orbit-process-{$id}-cli-proxy-api-collector.service
                path=/etc/systemd/system/\$unit
                test ! -L "\$path"
                if test -e "\$path"; then
                    grep -Fxq 'X-Orbit-Process-ID={$id}' "\$path"
                    systemctl disable --now "\$unit"
                    rm -f -- "\$path"
                    systemctl daemon-reload
                    systemctl reset-failed "\$unit" 2>/dev/null || true
                fi
                BASH;
            $result = $this->shell->run($node, new RemoteCommand(arguments: ['sudo', 'bash', '-seu'], input: $script));
            if (! $result->succeeded()) {
                throw new RuntimeException('Could not retire the owned collector unit.');
            }
            $fresh->update(['status' => 'provisioning']);
        });
        $this->cache->forgetPrefix('orbit:proxycli:');
    }

    public function complete(Process $process): void
    {
        $node = Node::query()->findOrFail($process->owner_id);
        $result = $this->shell->run($node, new RemoteCommand(arguments: ['sudo', 'rm', '-rf', '--', '/var/lib/orbit/proxycli', '/etc/caddy/orbit-proxycli-cert-current']));
        if (! $result->succeeded()) {
            throw new RuntimeException('Could not remove the old collector files after cutover.');
        }
        DB::table('ai_router_cutovers')->where('process_id', $process->id)->delete();
    }
}
