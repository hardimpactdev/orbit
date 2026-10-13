<?php

declare(strict_types=1);

namespace App\Providers;

use App\E2E\Git\GitRepository;
use App\E2E\GuestTransport;
use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\OrphanNetworkSweep;
use App\E2E\RoleImageBuilder;
use App\E2E\RoleImagePrograms;
use App\E2E\RoleImages;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\TopologyAcquirer;
use App\E2E\TopologyComposer;
use App\E2E\TopologyGuestPreparer;
use App\E2E\TopologyReleaser;
use App\E2E\TopologyVerifier;
use App\E2E\Value\OperationId;
use App\E2E\WorktreeLocator;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Process\Process;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OperationId::class, function (Application $app): OperationId {
            $value = $app->make(Repository::class)->get('e2e.incus.operation_id');
            $value = is_string($value) && $value !== '' ? $value : bin2hex(random_bytes(16));

            return new OperationId($value);
        });
        $repositoryRoot = dirname(__DIR__, 4);
        $this->app->singleton(GitRepository::class, fn (): GitRepository => new GitRepository($repositoryRoot));
        // Host-wide state (locks) lives in the primary checkout's `.e2e/`.
        $this->app->singleton(
            StatePaths::class,
            fn (): StatePaths => StatePaths::forPrimary(self::primaryCheckout($repositoryRoot)),
        );
        $this->app->singleton(AtomicJsonStore::class);
        $this->app->singleton(
            WorktreeLocator::class,
            fn (): WorktreeLocator => new WorktreeLocator(self::primaryCheckout($repositoryRoot)),
        );
        $this->app->singleton(SecretRedactor::class);
        $this->app->bind(OperationLock::class);
        $this->app->singleton(
            WorktreeSynchronizer::class,
            fn (Application $app): WorktreeSynchronizer => new WorktreeSynchronizer(
                $app->make(IncusHost::class),
                $repositoryRoot,
                $app->make(OperationId::class),
            ),
        );

        $this->app->singleton(IncusHost::class, function (Application $app): IncusHost {
            $configuration = $app->make(Repository::class);
            $ownership = $configuration->get('e2e.incus.ownership');
            $remote = $configuration->get('e2e.incus.remote');
            $project = $configuration->get('e2e.incus.project');
            $pool = $configuration->get('e2e.incus.storage_pool');

            if (! is_string($remote) || ! is_string($project) || ! is_string($pool)) {
                throw new \RuntimeException('Incus host configuration is invalid.');
            }

            if (! is_array($ownership) || array_is_list($ownership)) {
                throw new \RuntimeException('Incus ownership configuration is invalid.');
            }

            foreach ($ownership as $key => $value) {
                if (! is_string($key) || ! is_string($value)) {
                    throw new \RuntimeException('Incus ownership configuration is invalid.');
                }
            }

            return new IncusHost(
                remote: $remote,
                project: $project,
                pool: $pool,
                ownershipMetadata: $ownership,
                redactor: $app->make(SecretRedactor::class),
                guestReadinessTimeoutSeconds: self::seconds($configuration, 'e2e.readiness.guest_seconds'),
            );
        });
        $this->app->bind(GuestTransport::class, fn (Application $app): IncusHost => $app->make(IncusHost::class));
        $this->app->singleton(IncusNetworkLifecycle::class);
        $this->app->singleton(HostCapacity::class, function (Application $app): HostCapacity {
            $maxVms = $app->make(Repository::class)->get('e2e.incus.max_vms', 24);
            if ((is_float($maxVms) && is_finite($maxVms)) || (is_string($maxVms) && is_numeric($maxVms))) {
                $maxVms = (int) $maxVms;
            }
            if (! is_int($maxVms)) {
                throw new \RuntimeException('Incus host capacity is invalid.');
            }

            return new HostCapacity($app->make(IncusHost::class), $maxVms);
        });
        $this->app->singleton(TopologyGuestPreparer::class);
        $this->app->singleton(TopologyComposer::class);
        $this->app->singleton(
            TopologyVerifier::class,
            fn (Application $app): TopologyVerifier => new TopologyVerifier(
                $app->make(IncusHost::class),
                self::seconds($app->make(Repository::class), 'e2e.readiness.seconds'),
            ),
        );
        $this->app->singleton(RoleImagePrograms::class, fn (): RoleImagePrograms => new RoleImagePrograms($repositoryRoot));
        $this->app->singleton(RoleImages::class);
        $this->app->singleton(RoleImageBuilder::class, function (Application $app) use ($repositoryRoot): RoleImageBuilder {
            $configuration = $app->make(Repository::class);
            $vmSource = $configuration->get('e2e.images.vm_source');
            $containerSource = $configuration->get('e2e.images.container_source');
            if (! is_string($vmSource) || $vmSource === '' || ! is_string($containerSource) || $containerSource === '') {
                throw new \RuntimeException('The role image source configuration is invalid.');
            }

            return new RoleImageBuilder(
                $app->make(IncusHost::class),
                $app->make(IncusNetworkLifecycle::class),
                $app->make(HostCapacity::class),
                $app->make(StatePaths::class),
                $app->make(OperationId::class),
                $app->make(RoleImagePrograms::class),
                $repositoryRoot,
                $vmSource,
                $containerSource,
            );
        });
        $this->app->singleton(TopologyAcquirer::class, fn (Application $app): TopologyAcquirer => new TopologyAcquirer(
            host: $app->make(IncusHost::class),
            networks: $app->make(IncusNetworkLifecycle::class),
            images: $app->make(RoleImages::class),
            composer: $app->make(TopologyComposer::class),
            synchronizer: $app->make(WorktreeSynchronizer::class),
            verifier: $app->make(TopologyVerifier::class),
            guests: $app->make(TopologyGuestPreparer::class),
            capacity: $app->make(HostCapacity::class),
            hostPaths: $app->make(StatePaths::class),
            operation: $app->make(OperationId::class),
            repositoryRoot: $repositoryRoot,
        ));
        $this->app->singleton(OrphanNetworkSweep::class, fn (Application $app): OrphanNetworkSweep => new OrphanNetworkSweep(
            $app->make(IncusHost::class),
            $app->make(IncusNetworkLifecycle::class),
            $app->make(StatePaths::class),
            $app->make(OperationId::class),
        ));
        $this->app->singleton(TopologyReleaser::class, fn (Application $app): TopologyReleaser => new TopologyReleaser(
            $app->make(IncusHost::class),
            $app->make(IncusNetworkLifecycle::class),
            $app->make(StatePaths::class),
            $app->make(OperationId::class),
            $app->make(OrphanNetworkSweep::class),
        ));
    }

    private static function seconds(Repository $configuration, string $key): int
    {
        $value = $configuration->get($key);
        if (is_string($value) && preg_match('/\A[1-9][0-9]{0,5}\z/D', $value) === 1) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < 1) {
            throw new \RuntimeException("The setting [{$key}] must be a positive number of seconds.");
        }

        return $value;
    }

    /**
     * The primary checkout is the first entry of `git worktree list`; it is read
     * directly, not through the Process facade, so a faked process never moves
     * host state.
     */
    private static function primaryCheckout(string $repositoryRoot): string
    {
        $process = new Process(['git', 'worktree', 'list', '--porcelain'], $repositoryRoot);
        $process->run();
        if (! $process->isSuccessful() || preg_match('/^worktree (.+)$/m', $process->getOutput(), $match) !== 1) {
            throw new \RuntimeException('The primary Git worktree cannot be determined.');
        }
        $primary = realpath(trim($match[1]));
        if ($primary === false) {
            throw new \RuntimeException('The primary Git worktree does not exist.');
        }

        return $primary;
    }

    public function boot(): void
    {
        ini_set('zend.exception_ignore_args', '1');
    }
}
