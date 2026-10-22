<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Firewall\RouterLanIngressReconciler;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskReviewDiff;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\TaskVms\TaskVmPackageCacheMount;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\SshExecutor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Illuminate\Support\Facades\Storage;
use Tests\FeatureTestCase;
use Tests\Support\FakeClusterRouterDnsSelectionReconciler;
use Tests\Support\FakeHostKeyScanner;
use Tests\Support\FakeNodeAgentRuntime;
use Tests\Support\FakeNodeCaddyBuilds;
use Tests\Support\FakeRouterLanIngressReconciler;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskTurnReceipts;
use Tests\Support\FakeTaskVmPackageCacheMount;
use Tests\Support\FakeVitePortRuntime;
use Tests\Support\GuardsSudo;
use Tests\Support\NoSudo;
use Tests\Support\NullTaskReviewDiff;
use Tests\Support\SshGuardedProcessRunner;
use Tests\Support\UnreachableSshExecutor;

final class NativeFeatureIsolationTest extends FeatureTestCase
{
    public function test_keeps_feature_isolation_and_cleans_private_storage(): void
    {
        $traits = class_uses_recursive(self::class);
        foreach ([GuardsSudo::class, LazilyRefreshDatabase::class, WithCachedConfig::class, WithCachedRoutes::class] as $trait) {
            $this->assertContains($trait, $traits);
        }
        $this->assertNotSame('original-path', NoSudo::path('original-path'));
        foreach ([
            SshExecutor::class => UnreachableSshExecutor::class,
            HostKeyScanner::class => FakeHostKeyScanner::class,
            NativeProcessRunner::class => SshGuardedProcessRunner::class,
            NodeCaddyBuilds::class => FakeNodeCaddyBuilds::class,
            TaskVmPackageCacheMount::class => FakeTaskVmPackageCacheMount::class,
            VitePortRuntime::class => FakeVitePortRuntime::class,
            NodeAgentRuntime::class => FakeNodeAgentRuntime::class,
            RouterLanIngressReconciler::class => FakeRouterLanIngressReconciler::class,
            ClusterRouterDnsSelectionReconciler::class => FakeClusterRouterDnsSelectionReconciler::class,
            TaskTurnReceipts::class => FakeTaskTurnReceipts::class,
            TaskCheckRunner::class => FakeTaskCheckRunner::class,
            TaskReviewDiff::class => NullTaskReviewDiff::class,
        ] as $contract => $fake) {
            $this->assertInstanceOf($fake, app($contract), $contract);
        }
        $storage = storage_path();
        $this->assertStringStartsWith(sys_get_temp_dir().'/orbit-feature-storage-', $storage);
        Storage::disk('local')->put('fake-disk.txt', 'isolated');
        file_put_contents($storage.'/direct-file.txt', 'isolated');
        $this->assertFileExists(Storage::disk('local')->path('fake-disk.txt'));
        $this->assertFileExists($storage.'/direct-file.txt');
        $this->beforeApplicationDestroyed(function () use ($storage): void {
            $this->assertDirectoryDoesNotExist($storage);
        });
    }
}
