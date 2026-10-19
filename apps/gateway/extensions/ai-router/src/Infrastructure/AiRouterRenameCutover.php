<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Actions\Processes\DestroyProcessAction;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Fleet\NodeShell;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Domain\AiRouterCache;
use RuntimeException;

/** One-time removal inputs, never runtime aliases. Removed after live cutover in ADR 0212 step 4. */
final readonly class AiRouterRenameCutover
{
    public function __construct(
        private DestroyProcessAction $processes,
        private AiRouterCache $cache,
        private NodeShell $shell,
        private AiRouterSourcePublisher $source,
        private AiRouterCertificatePublisher $certificates,
    ) {}

    public function assertComplete(): void
    {
        if ($this->pending()) {
            throw new ResourceOperationException('ai_router.cutover_pending', 'The release cutover is pending. The next extension tick will retry it.', 409);
        }
    }

    public function pending(?int $nodeId = null): bool
    {
        $query = DB::table('ai_router_cutovers');

        return ($nodeId === null ? $query : $query->where('node_id', $nodeId))->exists();
    }

    public function retire(Node $node): void
    {
        $id = DB::table('ai_router_cutovers')->where('node_id', $node->id)->value('process_id');
        $process = Process::query()->where('id', $id)->first();
        if ($process !== null) {
            if ($process->owner_type !== Node::class || $process->owner_id !== $node->id || $process->name !== 'cli-proxy-api-collector') {
                throw new RuntimeException('The retired collector no longer matches its recorded owner.');
            }
            // Normal Process removal stops and deletes the owned unit under its existing runtime lease.
            $this->processes->execute($process, removedByOwningRole: true);
        }
        $this->cache->renameCollectorKeys('orbit:proxycli:', 'orbit:ai-router:');
    }

    public function complete(Node $node): void
    {
        foreach ([$this->source->removeCommand('/var/lib/orbit/proxycli'), $this->certificates->removeCommand('/etc/caddy/orbit-proxycli-cert-current')] as $command) {
            if (! $this->shell->run($node, $command)->succeeded()) {
                throw new RuntimeException('Could not remove the retired collector files.');
            }
        }
        DB::table('ai_router_cutovers')->where('node_id', $node->id)->delete();
    }
}
