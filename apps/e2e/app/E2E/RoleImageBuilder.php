<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\OperationId;
use App\E2E\Value\RoleImage;
use App\E2E\Value\TopologyNodeCatalog;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Builds role images the way Orbit builds the task VM base image: one builder per role from the stock image,
 * the role's programs, a manifest of their fingerprints, the identity removed, then a published image and the
 * role alias moved to it. Builders and their network always go; a failed build keeps the current images.
 */
final readonly class RoleImageBuilder
{
    public const string LOCK = 'topology-images';

    /** The Gateway's task VM image clean, shared so both image pipelines remove the same identity. */
    public const string GATEWAY_CLEAN_SCRIPT = '/apps/gateway/resources/task-vms/base-image-clean.sh';

    private const string ROLE_IMAGE_SCRIPT = '/apps/e2e/resources/image/role-image.sh';

    private const int PROGRAM_SECONDS = 3600;

    private const int SETTLE_SECONDS = 900;

    /** The first builder's address position on the build network; each next builder takes the next one. */
    private const int FIRST_ADDRESS = 10;

    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private HostCapacity $capacity,
        private StatePaths $hostPaths,
        private OperationId $operation,
        private RoleImagePrograms $programs,
        private string $repositoryRoot,
        private string $vmSource,
        private string $containerSource,
        private int $settleIntervalMicroseconds = 10_000_000,
    ) {}

    /**
     * @param  list<RoleImage>  $images
     * @param  (Closure(string, float): void)|null  $progress  hears each stage and its seconds
     * @return array<string, string> role image value => the fingerprint its alias names now
     */
    public function build(array $images, ?Closure $progress = null): array
    {
        if ($images === []) {
            throw new RuntimeException('Name at least one role image to build.');
        }
        $lock = new OperationLock($this->hostPaths);
        if (! $lock->acquire(self::LOCK, $this->operation, timeoutSeconds: 0.0)) {
            throw new RuntimeException('Another role image build is running.');
        }

        try {
            $roles = array_map(static fn (RoleImage $image): string => $image->value, $images);
            $programs = StageTimer::run($progress, 'render', fn (): array => array_combine(
                $roles,
                array_map($this->programs->for(...), $images),
            ));
            $commit = new GitRepository($this->repositoryRoot)->commit();
            $network = 'oe-'.substr(hash('sha256', 'images:'.$this->operation->value), 0, 12);
            $builders = array_combine($roles, array_map(
                fn (string $role): string => 'orbit-e2e-image-'.substr($this->operation->value, 0, 8).'-'.$role,
                $roles,
            ));

            $promoted = [];
            $published = [];
            try {
                StageTimer::run($progress, 'launch', fn () => $this->launch($images, $builders, $network));
                StageTimer::run($progress, 'start', function () use ($builders): void {
                    $this->host->startAll(array_values($builders));
                    $this->host->waitForAgents(array_values($builders));
                    $this->host->waitForGlobalIpv4(array_values($builders));
                    $this->settle(array_values($builders));
                });
                $sources = $this->host->instanceBaseImages();
                StageTimer::run($progress, 'programs', fn () => $this->runPrograms($builders, $programs));
                StageTimer::run($progress, 'manifest', fn () => $this->writeManifests($builders, $programs, $sources, $commit));
                StageTimer::run($progress, 'clean', fn () => $this->clean($builders));
                StageTimer::run($progress, 'stop', fn () => $this->host->stopAll(array_values($builders)));
                $stamp = gmdate('YmdHis');
                foreach ($images as $image) {
                    $published[$image->value] = StageTimer::run($progress, 'publish.'.$image->value, fn (): string => $this->host->publish(
                        $builders[$image->value],
                        $image->alias().'-'.$stamp,
                        [
                            RoleImages::PROGRAMS_PROPERTY => RoleImagePrograms::listFingerprint($programs[$image->value]),
                            RoleImages::COMMIT_PROPERTY => $commit,
                            'orbit.e2e.role' => $image->value,
                        ],
                        $image->instanceType(),
                    ));
                }
                foreach ($published as $role => $fingerprint) {
                    $this->host->pointAlias(RoleImage::from($role)->alias(), $fingerprint);
                    $promoted[$role] = $fingerprint;
                }
            } catch (Throwable $exception) {
                foreach (array_diff_key($published, $promoted) as $fingerprint) {
                    try {
                        $this->host->deleteImage($fingerprint);
                    } catch (Throwable) {
                        // The next build prunes an unused dated image.
                    }
                }

                throw $exception;
            } finally {
                StageTimer::run($progress, 'cleanup', fn () => $this->removeBuilders(array_values($builders), $network));
            }
            StageTimer::run($progress, 'prune', fn () => $this->prune($images));

            return $promoted;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<RoleImage>  $images
     * @param  array<string, string>  $builders
     */
    private function launch(array $images, array $builders, string $network): void
    {
        $creation = new OperationLock($this->hostPaths);
        if (! $creation->acquire(OrphanNetworkSweep::CREATION_LOCK, $this->operation, timeoutSeconds: 600)) {
            throw new RuntimeException('Another topology creation holds the host.');
        }
        try {
            $vms = count(array_filter($images, static fn (RoleImage $image): bool => ! $image->isContainer()));
            $slot = $this->capacity->reserveSlot(max(1, $vms));
            $metadata = ['user.orbit.e2e.operation' => $this->operation->value, 'user.orbit.e2e.purpose' => 'role-image'];
            $this->networks->create($network, $slot, $metadata, self::FIRST_ADDRESS + count($images) - 1);
            $catalog = TopologyNodeCatalog::load();
            $guests = [];
            foreach (array_values($images) as $index => $image) {
                $guests[$image->value] = [
                    'image' => $image->isContainer() ? $this->containerSource : $this->vmSource,
                    'name' => $builders[$image->value],
                    'network' => $network,
                    'node' => $image->value,
                    'address' => self::FIRST_ADDRESS + $index,
                    'memory' => $catalog->memoryFor($image),
                    'slot' => $slot,
                    'container' => $image->isContainer(),
                    'metadata' => [...$metadata, 'user.orbit.e2e.role-image' => $image->value],
                    // No default user: the cloud image would give uid 1000 to `ubuntu`, which the mount needs for orbit.
                    ...($image->isContainer() ? [] : ['config' => ['cloud-init.user-data' => "#cloud-config\nusers: []\n"]]),
                ];
            }
            $this->host->initGuests($guests);
        } finally {
            $creation->release();
        }
    }

    /**
     * Waits until every builder finished its first boot: cloud-init is done and the boot ID stays the same over
     * one interval. A VM from the stock image reboots once after the Incus agent writes its cloud-init seed, and
     * a program running across that reboot would fail.
     *
     * @param  list<string>  $builders
     */
    private function settle(array $builders): void
    {
        $probe = 'if command -v cloud-init >/dev/null 2>&1; then '
            .'case "$(cloud-init status 2>/dev/null)" in *done*|*disabled*) ;; *) exit 1 ;; esac; fi; '
            .'cat /proc/sys/kernel/random/boot_id';
        $previous = null;
        $deadline = microtime(true) + self::SETTLE_SECONDS;
        while (microtime(true) < $deadline) {
            $commands = [];
            foreach ($builders as $builder) {
                $commands[$builder] = ['instance' => $builder, 'command' => new GuestCommand(['sh', '-c', $probe], 15)];
            }
            try {
                $results = $this->host->execAll($commands);
                $boots = array_map(static fn (GuestCommandResult $result): ?string => $result->successful() ? trim($result->stdout) : null, $results);
            } catch (RuntimeException) {
                $boots = null;
            }
            if ($boots !== null && ! in_array(null, $boots, true) && $boots === $previous) {
                return;
            }
            $previous = $boots !== null && ! in_array(null, $boots, true) ? $boots : null;
            usleep($this->settleIntervalMicroseconds);
        }

        throw new RuntimeException('Role image build failed: a builder did not finish its first boot in time.');
    }

    /**
     * Runs program N of every role at once, then program N+1, so roles build in parallel.
     *
     * @param  array<string, string>  $builders
     * @param  array<string, list<array{name:string,user:string,arguments:list<string>,input:?string}>>  $programs
     */
    private function runPrograms(array $builders, array $programs): void
    {
        $rounds = array_reduce($programs, static fn (int $longest, array $list): int => max($longest, count($list)), 0);
        for ($round = 0; $round < $rounds; $round++) {
            $commands = [];
            foreach ($programs as $role => $list) {
                $program = $list[$round] ?? null;
                if ($program === null) {
                    continue;
                }
                $commands["{$role}.{$program['name']}"] = [
                    'instance' => $builders[$role],
                    'command' => $program['user'] === 'root'
                        ? new GuestCommand($program['arguments'], self::PROGRAM_SECONDS, $program['input'])
                        : GuestCommand::asOrbitUser($program['arguments'], self::PROGRAM_SECONDS, $program['input']),
                ];
            }
            $failed = [];
            foreach ($this->host->execAll($commands) as $label => $result) {
                if (! $result->successful()) {
                    $failed[] = (new GuestFailureDetail)->append("program {$label} exited {$result->exitCode}", $result->stderr);
                }
            }
            if ($failed !== []) {
                throw new RuntimeException('Role image build failed: '.implode('; ', $failed));
            }
        }
    }

    /**
     * @param  array<string, string>  $builders
     * @param  array<string, list<array{name:string,user:string,arguments:list<string>,input:?string}>>  $programs
     * @param  array<string, string>  $sources  instance => source image fingerprint
     */
    private function writeManifests(array $builders, array $programs, array $sources, string $commit): void
    {
        $commands = [];
        foreach ($builders as $role => $builder) {
            $fingerprints = [];
            foreach ($programs[$role] as $program) {
                $fingerprints[$program['name']] = RoleImagePrograms::fingerprint($program);
            }
            $manifest = json_encode([
                'schema' => RoleImage::MANIFEST_SCHEMA,
                'role' => $role,
                'commit' => $commit,
                'source_fingerprint' => $sources[$builder] ?? null,
                'programs_fingerprint' => RoleImagePrograms::listFingerprint($programs[$role]),
                'programs' => $fingerprints,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
            $commands[$role] = [
                'instance' => $builder,
                'command' => new GuestCommand([
                    'sh', '-c', 'set -e; umask 022; mkdir -p "${1%/*}"; cat >"$1.tmp"; mv "$1.tmp" "$1"', 'sh', RoleImage::MANIFEST_PATH,
                ], 60, $manifest),
            ];
        }
        foreach ($this->host->execAll($commands) as $role => $result) {
            if (! $result->successful()) {
                throw new RuntimeException((new GuestFailureDetail)->append(
                    "Role image build failed: the manifest of [{$role}] could not be written (exit {$result->exitCode}).",
                    $result->stderr,
                ));
            }
        }
    }

    /** @param array<string, string> $builders */
    private function clean(array $builders): void
    {
        // The Gateway's own task VM image clean removes the identity; `seal` then adds what a composed guest
        // needs on top: cloud-init off, and no topology identity left.
        $steps = [
            'removing the identity of' => [self::GATEWAY_CLEAN_SCRIPT, ['bash', '-s']],
            'sealing' => [self::ROLE_IMAGE_SCRIPT, ['bash', '-s', '--', 'seal']],
        ];
        foreach ($steps as $label => [$path, $argv]) {
            $script = file_get_contents($this->repositoryRoot.$path);
            if (! is_string($script) || $script === '') {
                throw new RuntimeException("The image script [{$path}] is missing.");
            }
            $commands = [];
            foreach ($builders as $role => $builder) {
                $commands[$role] = ['instance' => $builder, 'command' => new GuestCommand($argv, 600, $script)];
            }
            foreach ($this->host->execAll($commands) as $role => $result) {
                if (! $result->successful() || trim($result->stdout) !== '{"ok":true}') {
                    throw new RuntimeException((new GuestFailureDetail)->append(
                        "Role image build failed: {$label} [{$role}] exited {$result->exitCode}",
                        $result->stderr,
                    ));
                }
            }
        }
    }

    /**
     * Deletes the builders and their network, each only when it carries this build's operation.
     *
     * @param  list<string>  $builders
     */
    private function removeBuilders(array $builders, string $network): void
    {
        $present = [];
        foreach ($this->host->instances($builders) as $name => $instance) {
            if (($instance->metadata['user.orbit.e2e.operation'] ?? null) !== $this->operation->value) {
                throw new RuntimeException("The builder {$name} belongs to another operation.");
            }
            $present[] = $name;
        }
        if ($present !== []) {
            $this->host->forceStopAll($present);
            $this->host->deleteInstances($present);
        }
        $resource = $this->host->network($network);
        if ($resource !== null) {
            if (($resource->metadata['user.orbit.e2e.operation'] ?? null) !== $this->operation->value) {
                throw new RuntimeException("The build network {$network} belongs to another operation.");
            }
            $this->networks->delete($network);
        }
    }

    /**
     * Deletes the dated images of the built roles that their alias no longer names and no guest started from.
     *
     * @param  list<RoleImage>  $images
     */
    private function prune(array $images): void
    {
        $inventory = $this->host->images();
        $used = array_values($this->host->instanceBaseImages());
        foreach ($images as $image) {
            foreach ($inventory as $candidate) {
                $dated = array_filter($candidate['aliases'], static fn (string $alias): bool => str_starts_with($alias, $image->alias().'-'));
                if (
                    $dated === []
                    || in_array($image->alias(), $candidate['aliases'], true)
                    || in_array($candidate['fingerprint'], $used, true)
                ) {
                    continue;
                }
                $this->host->deleteImage($candidate['fingerprint']);
            }
        }
    }
}
