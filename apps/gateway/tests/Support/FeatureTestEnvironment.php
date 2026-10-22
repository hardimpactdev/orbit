<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Firewall\RouterLanIngressReconciler;
use App\Domain\Logs\LogStreamStore;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskReviewDiff;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\TaskVms\TaskVmPackageCacheMount;
use App\Infrastructure\Activity\ActivityShutdownFinalizer;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Logs\CacheLogStreamStore;
use App\Infrastructure\Nodes\NodeLocks;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\SshExecutor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Laravel\Ai\Classification;

/** Shared Feature-test guards and private storage, independent of Pest's lifecycle hooks. */
final readonly class FeatureTestEnvironment
{
    private function __construct(private RealStorageGuard $storageGuard, private string $storage) {}

    public static function start(): self
    {
        $guard = RealStorageGuard::watch(storage_path('app'));
        // Direct-file staging must be isolated too, not just writes through the Storage facade.
        $storage = sys_get_temp_dir().'/orbit-feature-storage-'.bin2hex(random_bytes(16));
        app()->useStoragePath($storage);
        Storage::fake('local');
        app()->instance(SshExecutor::class, new UnreachableSshExecutor);
        app()->instance(TaskVmPackageCacheMount::class, new FakeTaskVmPackageCacheMount);
        app()->instance(HostKeyScanner::class, new FakeHostKeyScanner);
        $processes = new SshGuardedProcessRunner(app(NativeProcessRunner::class));
        app()->instance(SshGuardedProcessRunner::class, $processes);
        app()->instance(NativeProcessRunner::class, $processes);
        app()->instance(VitePortRuntime::class, new FakeVitePortRuntime);
        app()->instance(NodeAgentRuntime::class, new FakeNodeAgentRuntime);
        app()->instance(RouterLanIngressReconciler::class, new FakeRouterLanIngressReconciler);
        app()->instance(ClusterRouterDnsSelectionReconciler::class, new FakeClusterRouterDnsSelectionReconciler);
        app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts);
        app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
        test_share_scheduler_graph();
        app()->instance(TaskReviewDiff::class, new NullTaskReviewDiff);
        // A Node Caddy build runs local `sudo` on a Gateway Node; tests record build requests instead.
        app()->instance(NodeCaddyBuilds::class, new FakeNodeCaddyBuilds);
        // The view's file store under ORBIT_HOME would outlive a test; each test gets its own.
        app()->instance(CacheAgentStateView::class, new CacheAgentStateView(Cache::store('array')));
        app()->instance(LogStreamStore::class, new CacheLogStreamStore(Cache::store('array')));
        // The same holds for the Node locks' file store.
        app()->instance(NodeLocks::class, new NodeLocks(Cache::store('array')));
        Classification::fake()->preventStrayClassifications();
        // Transitions wait for private DNS answers to expire; tests assert those waits instead.
        Sleep::fake(syncWithCarbon: true);
        // A streamed response a test never consumed stays armed; each test starts with none.
        ActivityShutdownFinalizer::forgetArmed();

        return new self($guard, $storage);
    }

    public function finish(): void
    {
        try {
            $this->storageGuard->assertUnchanged();
            app(SshGuardedProcessRunner::class)->assertNoStrayProcesses();
        } finally {
            File::deleteDirectory($this->storage);
        }
    }
}
