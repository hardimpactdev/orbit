<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Infrastructure\AgentCommands\WorkspaceAgentCommandRunner;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\TaskVms\TaskVmGit;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Illuminate\Support\Facades\Config;
use Tests\Support\HostPathGuardedProcessRunner;
use Tests\Support\LocalGitRefAgent;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        // The test application reads the tracked `.env.example`, as CI does, not the untracked `.env`. A checkout
        // without `.env` would otherwise give every test a PHP warning from the environment loader, and a
        // developer's `.env` would make local results differ from CI. Child processes that boot the Gateway
        // themselves, such as `artisan` calls, still read `.env` when it exists.
        $app->loadEnvironmentFrom('.env.example');

        $this->traitsUsedByTest = class_uses_recursive(static::class);

        if (isset(CachedState::$cachedConfig, $this->traitsUsedByTest[WithCachedConfig::class])) {
            $this->markConfigCached($app);
        }

        if (isset(CachedState::$cachedRoutes, $this->traitsUsedByTest[WithCachedRoutes::class])) {
            $app->booting(fn (): mixed => $this->markRoutesCached($app));
        }

        TestDatabaseGuard::register($app);
        $app->make(Kernel::class)->bootstrap();
        // No test may change the host's systemd units, dnsmasq fragments, or Orbit state through real sudo.
        // The guard wraps the native runner, so the container's ProcessRunner keeps its own class.
        $app->extend(
            NativeProcessRunner::class,
            static fn (ProcessRunner $runner): ProcessRunner => new HostPathGuardedProcessRunner($runner),
        );
        // Tests point GitHub URLs at local repositories, so a credentialed checkout fetch may use them.
        $app['config']->set('orbit.git.checkout_protocols', ['https', 'file']);
        // Each test gets its own task VM mirrors in the test's ORBIT_HOME, and tests point GitHub URLs at local repositories.
        $app->singleton(WorkspaceAgentCommandRunner::class, LocalGitRefAgent::class);
        $app->singleton(TaskVmGit::class, static fn (Application $app): TaskVmGit => new TaskVmGit(
            $app->make(ProcessRunner::class),
            $app->make(WorkspaceAgentCommandRunner::class),
            rtrim(Config::string('orbit.home'), '/').'/task-vm-git/'.bin2hex(random_bytes(4)),
            ['https', 'file'],
        ));

        return $app;
    }

    protected function fakeRepositoryBranches(string $defaultBranch = 'main'): void
    {
        $this->app->instance(
            RepositoryDefaultBranchResolver::class,
            new class($defaultBranch) implements RepositoryDefaultBranchResolver
            {
                public function __construct(
                    private readonly string $defaultBranch,
                ) {}

                public function resolve(string $repository, ProjectSourceAccess $source): string
                {
                    return $this->defaultBranch;
                }

                public function verify(string $repository, string $branch, ProjectSourceAccess $source): void {}
            },
        );
    }

    protected function markAsGateway(Node $node): Node
    {
        NodeRole::query()->updateOrCreate(
            ['node_id' => $node->id, 'role' => RoleName::Gateway],
            ['status' => LifecycleStatus::Active],
        );

        return $node->refresh();
    }
}
