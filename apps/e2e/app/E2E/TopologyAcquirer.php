<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\MountPath;
use App\E2E\Value\OperationId;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyConstructionInputs;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Acquire, sync, verify, and exec on one issue's discovery topology.
 *
 * Acquisition composes the declared Nodes: each guest starts from its role image on a new network, the
 * worktree is mounted on the checkout Nodes, a fresh test Gateway is bootstrapped, and every other Node is
 * enrolled through it. The attempt lives in `<worktree>/.e2e/` and the topology stays until `release`.
 */
final readonly class TopologyAcquirer
{
    /** Host `bin/bootstrap` owns vendor and the Gateway `.env`; guests never run Composer. */
    private const array REQUIRED_WORKTREE_FILES = [
        'apps/gateway/vendor/autoload.php',
        'apps/cli/vendor/autoload.php',
        'packages/php-sdk/vendor/autoload.php',
        'apps/gateway/.env',
    ];

    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private RoleImages $images,
        private TopologyComposer $composer,
        private WorktreeSynchronizer $synchronizer,
        private TopologyVerifier $verifier,
        private DiscoveryGuestPreparer $guests,
        private HostCapacity $capacity,
        private StatePaths $hostPaths,
        private OperationId $operation,
        private string $repositoryRoot = '',
        /** @var (Closure(): AttemptId)|null Mints the attempt identity; injectable so tests pin resource names. */
        private ?Closure $attempts = null,
    ) {}

    /** @param (Closure(string, float): void)|null $progress hears each composition step and its seconds */
    public function acquire(TopologyRequest $request, TopologyRecipe $recipe, ?Closure $progress = null): FeatureTopology
    {
        $this->assertRequestOwnership($request);
        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $lock = $this->issueLock($request->issue);
        try {
            if ($state->hasAttempt()) {
                throw new RuntimeException(
                    "{$request->issue} already has discovery attempt {$state->attemptId()->value}; release it first.",
                );
            }

            return $this->create($request, $state, $recipe, $progress ?? static function (string $step, float $seconds): void {});
        } finally {
            $lock->release();
        }
    }

    /**
     * Refresh discovery after a source change and publish the verified source binding.
     *
     * A quick sync proves the mount, installs the current guest helper scripts, and applies pending Gateway
     * migrations, then stops. It skips the readiness probes and leaves `topology.json` and the guest source
     * marker at the last successful full sync, so standalone `verify` still refuses a changed mount.
     */
    public function sync(TopologyRequest $request, bool $quick = false): FeatureTopology
    {
        $this->assertRequestOwnership($request);
        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $lock = $this->issueLock($request->issue);
        try {
            $topology = $state->requireTopology();
            $this->networks->reconcile($topology->target->network());
            $this->guests->assertSourceMounted($topology->target);
            if ($quick) {
                $this->synchronizer->installWorkingTreeGuestScripts($topology->target, $request->worktree);
                $this->guests->prepareGatewaySchema($topology->target);

                return $topology;
            }
            $source = $this->synchronizer->syncWorkingTree($topology->target, $request->worktree, $this->mountToken($state));
            $this->guests->prepareGatewaySchema($topology->target);
            $updated = $this->withSource($topology, $source, $this->ready($topology->target, $source));
            $state->writeTopology($updated);

            return $updated;
        } finally {
            $lock->release();
        }
    }

    public function verify(TopologyRequest $request): FeatureTopology
    {
        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $lock = $this->issueLock($request->issue);
        try {
            $topology = $state->requireTopology();
            $this->synchronizer->assertWorkingTreeMatches(
                $topology->target,
                $request->worktree,
                $topology->source,
                $state->mountTokenHash(),
            );
            $this->networks->reconcile($topology->target->network());
            $this->guests->assertSourceMounted($topology->target);
            $updated = $this->withSource($topology, $topology->source, $this->ready($topology->target, $topology->source));
            $state->writeTopology($updated);

            return $updated;
        } finally {
            $lock->release();
        }
    }

    /** @param list<string> $argv */
    public function execute(
        TopologyRequest $request,
        string $node,
        array $argv,
        ?string $stdin = null,
        int $timeout = 60,
    ): GuestCommandResult {
        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $lock = $this->issueLock($request->issue);
        try {
            $instance = $this->ownedInstance($state->requireTopology(), $node);

            return $this->host->exec($instance, GuestCommand::asOrbitUser($argv, $timeout, $stdin));
        } finally {
            $lock->release();
        }
    }

    /** The exact guest of one Node, revalidated against the record; `shell` attaches to it. */
    public function instance(TopologyRequest $request, string $node): string
    {
        $state = IssueState::forWorktree($request->issue, $request->worktree);

        return $this->ownedInstance($state->requireTopology(), $node);
    }

    /** @param Closure(string, float): void $progress */
    private function create(TopologyRequest $request, IssueState $state, TopologyRecipe $recipe, Closure $progress): FeatureTopology
    {
        $this->assertMountableWorktree($request->worktree);
        $this->assertBootstrappedWorktree($request->worktree);

        $attempt = $this->attempts === null ? AttemptId::generate() : ($this->attempts)();
        $target = TopologyTarget::feature($request->issue, $attempt, $recipe);
        $mount = [
            'device' => FeatureTopology::SOURCE_DEVICE,
            'source' => $request->worktree,
            'path' => MountPath::GUEST_SOURCE,
        ];
        $mounts = array_fill_keys($recipe->checkoutNodeKeys(), $mount);
        $state->writeAttempt($attempt, $this->operation, $recipe);
        try {
            $mountToken = $state->writeMountToken();
            $construction = $this->step($progress, 'reserve', fn (): TopologyConstructionInputs => $this->construct($target, $mounts));
            $this->step($progress, 'start', function () use ($target): void {
                $instances = array_values($target->instances());
                $this->host->startAll($instances);
                $this->host->waitForAgents($instances);
                $this->host->waitForGlobalIpv4($instances);
                $this->guests->assertSourceMounted($target);
            });
            $source = $this->step($progress, 'scripts', fn (): SourceState => $this->synchronizer->syncWorkingTree($target, $request->worktree, $mountToken));
            $this->step($progress, 'compose', fn () => $this->composer->compose($target, $progress));
            $verification = $this->step($progress, 'readiness', fn (): VerificationReport => $this->ready($target, $source));
            $topology = new FeatureTopology($construction, $source, $verification, $mounts);
            $state->writeTopology($topology);

            return $topology;
        } catch (Throwable $exception) {
            $this->rollback($target, $state, $exception);
        }
    }

    /**
     * Under the host creation lock: reserve capacity, create the network, and create every guest from the
     * role image its alias names right now.
     *
     * @param  array<string, array{device:string,source:string,path:string}>  $mounts
     */
    private function construct(TopologyTarget $target, array $mounts): TopologyConstructionInputs
    {
        if ($this->host->network($target->network()) !== null || $this->host->instances(array_values($target->instances())) !== []) {
            throw new RuntimeException('A resource of this attempt already exists and cannot be adopted.');
        }
        $creation = new OperationLock($this->hostPaths);
        if (! $creation->acquire(OrphanNetworkSweep::CREATION_LOCK, $this->operation, timeoutSeconds: 600)) {
            throw new RuntimeException('Another topology creation holds the host.');
        }
        try {
            $images = $this->images->resolve($target->recipe->images());
            $slot = $this->capacity->reserveSlot($target->recipe->vmCount());
            $metadata = [
                'user.orbit.e2e.issue' => $target->issue,
                'user.orbit.e2e.attempt' => $target->attempt->value,
                'user.orbit.e2e.operation' => $this->operation->value,
            ];
            $this->networks->create($target->network(), $slot, $metadata, 14);
            $guests = [];
            foreach ($target->recipe->nodes as $node) {
                $guests[$node->key] = [
                    'image' => $images[$node->image->value],
                    'name' => $target->instance($node->key),
                    'network' => $target->network(),
                    'node' => $node->key,
                    'address' => $node->address,
                    'slot' => $slot,
                    'container' => $node->isContainer(),
                    'metadata' => [...$metadata, 'user.orbit.e2e.role-image' => $images[$node->image->value]],
                    ...(isset($mounts[$node->key]) ? ['mount' => $mounts[$node->key]] : []),
                ];
            }
            $this->host->initGuests($guests);

            return TopologyConstructionInputs::create($target, $slot, $images);
        } finally {
            $creation->release();
        }
    }

    private function ready(TopologyTarget $target, SourceState $source): VerificationReport
    {
        $verification = $this->verifier->verify($target, $source);
        if (! $verification->passed) {
            throw new RuntimeException('Topology readiness verification failed.'.$verification->failedSummary());
        }

        return $verification;
    }

    /**
     * @template T
     *
     * @param  Closure(string, float): void  $progress
     * @param  Closure(): T  $work
     * @return T
     */
    private function step(Closure $progress, string $name, Closure $work): mixed
    {
        $started = microtime(true);
        try {
            $result = $work();
        } catch (Throwable $exception) {
            throw str_starts_with($exception->getMessage(), 'Step ')
                ? $exception
                : new RuntimeException("Step {$name} failed: ".$exception->getMessage(), previous: $exception);
        }
        $progress($name, microtime(true) - $started);

        return $result;
    }

    /** Roll every intended resource back, drop the lease, and rethrow. */
    private function rollback(TopologyTarget $target, IssueState $state, Throwable $exception): never
    {
        $resources = [$target->network(), ...array_values($target->instances())];
        $rollback = AcquisitionRollback::forHost($this->host, $this->networks, $target);
        $refused = [];
        try {
            $cleanup = $rollback->cleanup($target, $resources, $rollback->observe($resources), $this->operation);
            foreach ($cleanup as $resource => $result) {
                if (! in_array($result, ['absent', 'removed'], true)) {
                    $refused[] = "{$resource}={$result}";
                }
            }
        } catch (Throwable $cleanupFailure) {
            $refused[] = 'cleanup: '.$cleanupFailure->getMessage();
        }
        if ($refused !== []) {
            // The lease stays so `release` can finish the cleanup.
            throw new RuntimeException(
                'Topology acquisition failed: '.$exception->getMessage().'; rollback was refused: '
                    .implode('; ', $refused),
                previous: $exception,
            );
        }
        $state->forgetAttempt();

        throw new RuntimeException('Topology acquisition failed: '.$exception->getMessage(), previous: $exception);
    }

    private function mountToken(IssueState $state): string
    {
        return $state->mountTokenHash() ?? $state->writeMountToken();
    }

    private function ownedInstance(FeatureTopology $topology, string $node): string
    {
        if (! $topology->target->recipe->hasNode($node)) {
            throw new InvalidArgumentException(
                "The topology has no Node [{$node}]; it has ".implode(', ', $topology->target->recipe->nodeKeys()).'.',
            );
        }
        $instance = $topology->target->instance($node);
        $owned = $this->host->instance($instance);
        if (
            $owned === null
            || ($owned->metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e'
            || ($owned->metadata['user.orbit.e2e.issue'] ?? null) !== $topology->target->issue
            || ($owned->metadata['user.orbit.e2e.attempt'] ?? null) !== $topology->attempt->value
            || $owned->network !== $topology->network
        ) {
            throw new RuntimeException('Incus instance identity does not match the topology record.');
        }

        return $instance;
    }

    private function withSource(FeatureTopology $topology, SourceState $source, VerificationReport $verification): FeatureTopology
    {
        return new FeatureTopology($topology->construction, $source, $verification, $topology->mounts);
    }

    private function issueLock(string $issue): OperationLock
    {
        $lock = new OperationLock($this->hostPaths);
        if (! $lock->acquire('topology-'.$issue, $this->operation)) {
            throw new RuntimeException('The issue topology is locked by another harness command.');
        }

        return $lock;
    }

    /** The worktree becomes an Incus disk source verbatim, so it must satisfy the mount path rule. */
    private function assertMountableWorktree(string $worktree): void
    {
        if (! MountPath::isMountableDirectory($worktree)) {
            throw new RuntimeException(
                'The worktree cannot be mounted: it must be an existing absolute directory path '
                .'without symlinks, commas, equals signs, or line breaks.',
            );
        }
    }

    private function assertBootstrappedWorktree(string $worktree): void
    {
        foreach (self::REQUIRED_WORKTREE_FILES as $file) {
            if (! is_file($worktree.'/'.$file)) {
                throw new RuntimeException(
                    "The worktree is missing {$file}; run bin/bootstrap in the worktree before discovery.",
                );
            }
        }
    }

    private function assertRequestOwnership(TopologyRequest $request): void
    {
        $repository = new GitRepository($request->worktree);
        $expectedRoot = $this->repositoryRoot !== '' ? $this->repositoryRoot : dirname(__DIR__, 4);
        if ($repository->commonDirectory() !== new GitRepository($expectedRoot)->commonDirectory()) {
            throw new InvalidArgumentException('The worktree repository identity does not match Orbit.');
        }
        if (! TopologyTarget::issueMatchesBranch($request->issue, $repository->branch())) {
            throw new InvalidArgumentException('The worktree branch does not match the issue.');
        }
        if (function_exists('posix_geteuid') && fileowner($request->worktree) !== posix_geteuid()) {
            throw new InvalidArgumentException('The worktree ownership does not match the current user.');
        }
    }
}
