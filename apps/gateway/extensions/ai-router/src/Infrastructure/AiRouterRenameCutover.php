<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
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
        private RemoteProgramRunner $programs,
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
            $this->run($fresh, 'retire');
            $fresh->update(['status' => 'provisioning']);
        });
        $this->cache->renameCollectorKeys('orbit:proxycli:', 'orbit:ai-router:');
    }

    public function complete(Process $process): void
    {
        $this->run($process, 'complete');
        DB::table('ai_router_cutovers')->where('process_id', $process->id)->delete();
    }

    private function run(Process $process, string $phase): void
    {
        $node = Node::query()->findOrFail($process->owner_id);
        $program = new RemoteProgramCatalog(base_path('extensions/ai-router/resources/remote'))
            ->program('ai-router.retire-collector', user: 'root');
        $result = $this->programs->run($node, $program, [(string) $process->id, $phase]);
        if ($result->outcome !== 'succeeded') {
            throw new RuntimeException('Could not finish collector cutover phase ['.$phase.'].');
        }
    }
}
