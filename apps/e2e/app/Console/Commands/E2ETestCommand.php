<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\DockerHost;
use App\E2E\Support\DockerTopologyNetworkPlan;
use App\E2E\Support\DockerTopologyProvider;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EPreparedTopology;
use App\E2E\Support\E2ETopologyArtifactNamespace;
use App\E2E\Support\E2ETopologyCapabilities;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyProviderPool;
use App\E2E\Support\IncusTopologyTemplate;
use App\E2E\Support\IncusWarmTopologyPool;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\FakeInvokedProcess;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Orbit\Core\OrbitCoreServiceProvider;
use Orbit\Core\Updates\UnattendedUpgradesAptConfig;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

#[Signature('e2e:test
    {--lanes= : Comma-separated lanes to run. Defaults to ORBIT_E2E_LANES or docker,incus}
    {--canary : Restrict the Docker lane to the e2e-feature-canary group only}
    {--sequential-lanes : Run selected lanes one after another}
    {--sequential-tests : Run selected tests without Pest parallel mode}
    {--dry-run : Print the lane commands without executing them}
    {--json : Output dry-run or failure details as JSON}')]
#[Description('Run prepared-topology E2E lanes')]
class E2ETestCommand extends Command
{
    private const string E2E_PLAN_PREFIX = '[orbit-e2e-plan] ';

    #[\Override]
    protected $hidden = true;

    private bool $interruptCleanupStarted = false;

    /**
     * @var array<string, InvokedProcess>
     */
    private array $runningProcesses = [];

    /**
     * @var array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>
     */
    private array $activePlans = [];

    /**
     * @var (Closure(array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>}>): array<string, string>)|null
     */
    private ?Closure $laneAvailabilityResolver = null;

    /**
     * @var list<class-string>
     */
    private array $requiredRuntimeDependencyClasses = [
        OrbitCoreServiceProvider::class,
        UnattendedUpgradesAptConfig::class,
    ];

    public function __construct()
    {
        parent::__construct();

        $this->ignoreValidationErrors();
    }

    /**
     * @param  Closure(array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>): array<string, string>  $resolver
     */
    public function setLaneAvailabilityResolver(Closure $resolver): void
    {
        $this->laneAvailabilityResolver = $resolver;
    }

    /**
     * @param  list<class-string>  $classes
     */
    public function setRequiredRuntimeDependencyClasses(array $classes): void
    {
        $this->requiredRuntimeDependencyClasses = $classes;
    }

    public function handle(): int
    {
        $passThroughArguments = $this->passThroughArguments();

        try {
            $plans = $this->lanePlans(
                lanes: $this->selectedLanes(),
                passThroughArguments: $passThroughArguments,
            );

            $plans = $this->reserveSelectedIncusHostsOutOfDockerPlan($plans);
        } catch (\InvalidArgumentException $exception) {
            return $this->failCommand($exception->getMessage());
        }

        try {
            $capacityMessage = $this->validateLaneCapacity($plans);
        } catch (\InvalidArgumentException $exception) {
            return $this->failCommand($exception->getMessage());
        }

        if ($capacityMessage !== null) {
            return $this->failCommand($capacityMessage);
        }

        if ((bool) $this->option('dry-run')) {
            return $this->renderDryRun($plans);
        }

        try {
            $this->validateRuntimeDependencies();
        } catch (\InvalidArgumentException $exception) {
            return $this->failCommand($exception->getMessage());
        }

        try {
            return $this->runPlans($plans);
        } catch (\InvalidArgumentException $exception) {
            return $this->failCommand($exception->getMessage());
        }
    }

    /**
     * @return list<string>
     */
    private function selectedLanes(): array
    {
        $value = (string) ($this->option('lanes') ?: getenv('ORBIT_E2E_LANES') ?: 'docker,incus');
        $lanes = array_values(array_unique(array_filter(
            array_map(
                fn (string $lane): string => strtolower(trim($lane)),
                explode(',', $value),
            ),
            fn (string $lane): bool => $lane !== '',
        )));

        if ($lanes === ['all']) {
            return ['docker', 'incus'];
        }

        if ($lanes === []) {
            throw new \InvalidArgumentException('No E2E lanes selected.');
        }

        $unsupported = array_values(array_diff($lanes, ['docker', 'incus']));

        if ($unsupported !== []) {
            throw new \InvalidArgumentException(
                'Unsupported E2E lane(s): '.implode(', ', $unsupported).'. Supported lanes: docker, incus.',
            );
        }

        return $lanes;
    }

    /**
     * @param  list<string>  $lanes
     * @param  list<string>  $passThroughArguments
     * @return array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>
     */
    private function lanePlans(array $lanes, array $passThroughArguments): array
    {
        $plans = [];

        foreach ($lanes as $lane) {
            $plans[$lane] = match ($lane) {
                'docker' => $this->dockerPlan($passThroughArguments),
                'incus' => $this->incusPlan($passThroughArguments),
                default => throw new \InvalidArgumentException("Unsupported E2E lane [{$lane}]."),
            };
        }

        return $plans;
    }

    /**
     * @param  array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>  $plans
     */
    private function validateLaneCapacity(array $plans): ?string
    {
        if (isset($plans['docker'])) {
            $message = $this->validateDockerLaneCapacity($plans);

            if ($message !== null) {
                return $message;
            }
        }

        if (isset($plans['incus'])) {
            return $this->validateIncusLaneCapacity($plans);
        }

        return null;
    }

    /**
     * @param  array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>  $plans
     */
    private function validateDockerLaneCapacity(array $plans): ?string
    {
        return $this->withPlanEnvironment($plans['docker'], function () use ($plans): ?string {
            $config = E2EConfig::fromEnvironment();

            if ($config->dockerHostSlots === []) {
                return 'Docker E2E capacity is not configured. Set ORBIT_E2E_DOCKER_TEST_RUNNERS=host:slots:containers.';
            }

            $containersPerWorker = $this->dockerLaneMaxContainerCount($plans['docker']['test_files'] ?? []);

            foreach ($config->dockerHostSlots as $host => $slots) {
                $requiredHostContainers = $slots * $containersPerWorker;
                $hostContainerCap = $config->dockerMaxContainersForHost($host);

                if ($hostContainerCap >= $requiredHostContainers) {
                    continue;
                }

                return "Docker host [{$host}] needs a container cap of at least {$requiredHostContainers} for {$slots} Docker slots.";
            }

            return null;
        });
    }

    /**
     * @param  array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>  $plans
     */
    private function validateIncusLaneCapacity(array $plans): ?string
    {
        return $this->withPlanEnvironment($plans['incus'], function () use ($plans): ?string {
            $config = E2EConfig::fromEnvironment();
            $hostCaps = [];

            foreach ($config->incusHostCandidates() as $host) {
                try {
                    $hostCaps[$host] = $config->incusMaxVmsForHost($host);
                } catch (\InvalidArgumentException $exception) {
                    return $exception->getMessage();
                }
            }

            foreach ($this->incusLaneTopologyKinds($plans['incus']['test_files'] ?? []) as $kind) {
                $requiredVms = count(IncusTopologyTemplate::rolesFor($kind));
                $fits = array_any($hostCaps, fn (int $hostCap): bool => $hostCap >= $requiredVms);

                if (! $fits) {
                    return "Incus prepared topology [{$kind->value}] requires {$requiredVms} VMs on one host. Increase ORBIT_E2E_INCUS_HOST_VM_CAPS for at least one candidate host.";
                }

                if (
                    IncusWarmTopologyPool::enabled()
                    && IncusWarmTopologyPool::availableHostSlots($config, $kind) === []
                ) {
                    return "Incus warm prepared topology [{$kind->value}] is missing. Run composer e2e:prepare-warm-topology -- --force {$kind->value}.";
                }
            }

            return null;
        });
    }

    /**
     * @param  list<string>  $passThroughArguments
     * @return array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}
     */
    private function dockerPlan(array $passThroughArguments): array
    {
        $testPath = null;
        $testFiles = [];
        $hasExplicitTestPath = $this->hasExplicitTestPath($passThroughArguments);
        $group = (bool) $this->option('canary') ? 'e2e-feature-canary' : 'e2e-feature';
        $command = [
            'php',
            'artisan',
            'test',
            '--testsuite=E2E',
            "--group={$group}",
            '--exclude-group=e2e-topology-contract',
            '--exclude-group=e2e-provider-incus',
        ];

        if ($hasExplicitTestPath) {
            $testFiles = $this->explicitTestFiles($passThroughArguments, 'docker');
        } else {
            $testPath = 'tests/Feature/Commands/.docker-feature-tests/'.$this->dockerTestRunDirectory();
            $testFiles = $this->dockerTestFiles();
            $command[] = $testPath;
        }

        $config = E2EConfig::fromEnvironment();
        $this->ensureDockerCapacityConfigured($config);

        $processes = $this->dockerRequestedProcessCount($config);
        $environment = [
            'ORBIT_E2E' => '1',
            'ORBIT_E2E_TOPOLOGY_PROVIDER' => 'docker',
            'ORBIT_E2E_TOPOLOGY_PROVIDERS' => 'docker',
            'ORBIT_E2E_GATEWAY_API' => '1',
            'ORBIT_E2E_TOPOLOGY_CACHE' => $this->topologyCache(),
            'ORBIT_E2E_CHECKOUT_CACHE' => 'process',
            'ORBIT_E2E_FAIL_ON_TOPOLOGY_UNAVAILABLE' => '1',
        ];
        $environment = [
            ...$environment,
            ...$this->topologyCacheLimitEnvironment(),
            ...$this->topologyArtifactEnvironment(),
            ...$this->runtimeIsolationEnvironment(),
        ];

        $capacity = $this->dockerCapacityPlan($config, $testFiles, $processes);

        if ($capacity !== null) {
            $processes = $capacity['processes'];
            $environment['ORBIT_E2E_DOCKER_TEST_RUNNERS'] = $this->renderDockerTestRunners(
                $capacity['host_slots'],
                $config,
            );
            $environment['ORBIT_E2E_PARALLEL_PROCESSES'] = (string) $processes;
        }

        if ($this->shouldRunTestsInParallel($processes, $passThroughArguments)) {
            $command[] = '--parallel';
            $command[] = "--processes={$processes}";
        }

        $plan = [
            'lane' => 'docker',
            'command' => [...$command, ...$passThroughArguments],
            'environment' => $environment,
        ];

        if ($testPath !== null) {
            $plan['test_path'] = $testPath;
        }

        if ($testFiles !== []) {
            $plan['test_files'] = $testFiles;
        }

        return $plan;
    }

    private function dockerTestRunDirectory(): string
    {
        return 'run_'.getmypid().'_'.bin2hex(random_bytes(4));
    }

    /**
     * @param  list<string>  $passThroughArguments
     * @return array{lane: string, command: list<string>, environment: array<string, string>, timings_file?: string}
     */
    private function incusPlan(array $passThroughArguments): array
    {
        $testPath = null;
        $testFiles = [];
        $hasExplicitTestPath = $this->hasExplicitTestPath($passThroughArguments);
        $command = [
            'php',
            'artisan',
            'test',
            '--testsuite=E2E',
            '--group=e2e-provider-incus',
            '--exclude-group=e2e-provision',
            '--exclude-group=e2e-topology-contract',
        ];

        if ($hasExplicitTestPath) {
            $testFiles = $this->explicitTestFiles($passThroughArguments, 'incus');
        } else {
            $testPath = 'tests/Feature/Commands/.incus-feature-tests/'.$this->incusTestRunDirectory();
            $testFiles = $this->incusTestFiles();
        }

        $processes = $this->incusRequestedProcessCount();

        if ($this->shouldRunTestsInParallel($processes, $passThroughArguments)) {
            $command[] = '--parallel';
            $command[] = "--processes={$processes}";
        }

        if ($testPath !== null) {
            $command[] = $testPath;
        }

        $plan = [
            'lane' => 'incus',
            'command' => [...$command, ...$passThroughArguments],
            'environment' => [
                'ORBIT_E2E' => '1',
                'ORBIT_E2E_TOPOLOGY_PROVIDER' => 'incus',
                'ORBIT_E2E_TOPOLOGY_PROVIDERS' => 'incus',
                'ORBIT_E2E_GATEWAY_API' => '1',
                'ORBIT_E2E_INCUS_HOST_SLOTS' => '',
                'ORBIT_E2E_TOPOLOGY_CACHE' => $this->topologyCache(),
                'ORBIT_E2E_CHECKOUT_CACHE' => 'process',
                'ORBIT_E2E_FAIL_ON_TOPOLOGY_UNAVAILABLE' => '1',
                ...$this->topologyCacheLimitEnvironment(),
                ...$this->topologyArtifactEnvironment(),
                ...$this->runtimeIsolationEnvironment(),
            ],
        ];

        if ($testPath !== null) {
            $plan['test_path'] = $testPath;
        }

        if ($testFiles !== []) {
            $plan['test_files'] = $testFiles;
        }

        return $plan;
    }

    private function incusTestRunDirectory(): string
    {
        return 'run_'.getmypid().'_'.bin2hex(random_bytes(4));
    }

    private function validateRuntimeDependencies(): void
    {
        $missingClasses = array_values(array_filter(
            $this->requiredRuntimeDependencyClasses,
            fn (string $class): bool => ! class_exists($class),
        ));

        if ($missingClasses === []) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'E2E runtime dependencies are missing or Composer autoload is stale. Missing classes: %s. Run `cd apps/e2e && composer install` from the repository root, then rerun the E2E lane.',
            implode(', ', $missingClasses),
        ));
    }

    private function topologyCache(): string
    {
        $cache = getenv('ORBIT_E2E_TOPOLOGY_CACHE');

        return is_string($cache) && $cache !== '' ? $cache : 'process';
    }

    /**
     * @return array<string, string>
     */
    private function topologyCacheLimitEnvironment(): array
    {
        $limit = getenv('ORBIT_E2E_TOPOLOGY_CACHE_LIMIT');

        if (is_string($limit) && $limit !== '') {
            return ['ORBIT_E2E_TOPOLOGY_CACHE_LIMIT' => $limit];
        }

        return ['ORBIT_E2E_TOPOLOGY_CACHE_LIMIT' => '1'];
    }

    /**
     * @return array<string, string>
     */
    private function topologyArtifactEnvironment(): array
    {
        $namespace = getenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE');

        if (! is_string($namespace) || $namespace === '') {
            return [];
        }

        return ['ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => $namespace];
    }

    /**
     * @return array<string, string>
     */
    private function runtimeIsolationEnvironment(): array
    {
        $instancePrefix = getenv('ORBIT_E2E_INSTANCE_PREFIX');

        if (is_string($instancePrefix) && $instancePrefix !== '') {
            return ['ORBIT_E2E_INSTANCE_PREFIX' => $instancePrefix];
        }

        return [
            'ORBIT_E2E_INSTANCE_PREFIX' => E2ETopologyArtifactNamespace::runtimeInstancePrefix('orbit-e2e'),
        ];
    }

    /**
     * @param  list<string>  $testFiles
     * @param  array<string, true>  $excludedHosts
     * @return array{host_slots: array<string, int>, processes: int}|null
     */
    private function dockerCapacityPlan(
        E2EConfig $config,
        array $testFiles,
        int $requestedProcesses,
        array $excludedHosts = [],
    ): ?array {
        if ($config->dockerHostSlots === []) {
            return null;
        }

        $containersPerWorker = $this->dockerLaneMaxContainerCount($testFiles);
        $hostSlots = [];

        foreach ($config->dockerHostSlots as $host => $slots) {
            if (isset($excludedHosts[strtolower($host)])) {
                continue;
            }

            $capacitySlots = intdiv($config->dockerMaxContainersForHost($host), $containersPerWorker);

            if ($capacitySlots < 1) {
                return null;
            }

            $hostSlots[$host] = min($slots, $capacitySlots);
        }

        $hostSlots = $this->limitHostSlots(
            $hostSlots,
            min($requestedProcesses, DockerTopologyNetworkPlan::maxRunScopedParallelWorkers()),
        );

        $processes = array_sum($hostSlots);

        if ($processes < 1) {
            return null;
        }

        return [
            'host_slots' => $hostSlots,
            'processes' => $processes,
        ];
    }

    /**
     * @param  list<string>  $passThroughArguments
     */
    private function shouldRunTestsInParallel(int $processes, array $passThroughArguments): bool
    {
        return (
            $processes > 1
            && ! (bool) $this->option('sequential-tests')
            && ! $this->hasListTestsArgument($passThroughArguments)
        );
    }

    private function dockerRequestedProcessCount(E2EConfig $config): int
    {
        $configuredProcesses = $this->envIntOrNull('ORBIT_E2E_PARALLEL_PROCESSES');

        if ($configuredProcesses !== null) {
            return $configuredProcesses;
        }

        return max(1, array_sum($config->dockerHostSlots));
    }

    private function ensureDockerCapacityConfigured(E2EConfig $config): void
    {
        if ($config->dockerHostSlots !== []) {
            return;
        }

        throw new \InvalidArgumentException(
            'Docker E2E capacity is not configured. Set ORBIT_E2E_DOCKER_TEST_RUNNERS=host:slots:containers.',
        );
    }

    /**
     * @param  array<string, int>  $hostSlots
     * @return array<string, int>
     */
    private function limitHostSlots(array $hostSlots, int $processes): array
    {
        while (array_sum($hostSlots) > $processes) {
            $largestSlotCount = max($hostSlots);

            foreach ($hostSlots as $host => $slots) {
                if ($slots !== $largestSlotCount) {
                    continue;
                }

                $hostSlots[$host] = $slots - 1;

                break;
            }
        }

        return array_filter(
            $hostSlots,
            fn (int $slots): bool => $slots > 0,
        );
    }

    /**
     * @param  list<string>  $testFiles
     */
    private function dockerLaneMaxContainerCount(array $testFiles): int
    {
        $kinds = $this->dockerLaneTopologyKinds($testFiles);

        if ($kinds === []) {
            return DockerTopologyProvider::maxContainerCountForAnyTopology();
        }

        return max(array_map(DockerTopologyProvider::containerCountFor(...), $kinds));
    }

    /**
     * @param  list<string>  $testFiles
     * @return list<E2ETopologyKind>
     */
    private function dockerLaneTopologyKinds(array $testFiles): array
    {
        $kinds = [];

        foreach ($testFiles as $testFile) {
            foreach ($this->topologyKindsInTestFile($testFile) as $kind) {
                $kinds[$kind->value] = $kind;
            }
        }

        return array_values($kinds);
    }

    /**
     * @param  array<string, int>  $hostSlots
     */
    private function renderDockerTestRunners(array $hostSlots, E2EConfig $config): string
    {
        return implode(',', array_map(
            fn (string $host, int $slots): string => "{$host}:{$slots}:{$config->dockerMaxContainersForHost($host)}",
            array_keys($hostSlots),
            array_values($hostSlots),
        ));
    }

    private function incusRequestedProcessCount(): int
    {
        return $this->envInt('ORBIT_E2E_INCUS_PARALLEL_PROCESSES', 1);
    }

    /**
     * @param  list<string>  $testFiles
     * @return list<E2ETopologyKind>
     */
    private function incusLaneTopologyKinds(array $testFiles): array
    {
        $kinds = [];

        foreach ($testFiles as $testFile) {
            foreach ($this->topologyKindsInTestFile($testFile) as $kind) {
                $kinds[$kind->value] = $kind;
            }
        }

        return array_values($kinds);
    }

    /**
     * @return list<string>
     */
    private function incusTestFiles(): array
    {
        $directory = repo_path('apps/e2e/tests/Feature/Commands');

        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $path = $file->getPathname();

            if (
                str_contains($path, DIRECTORY_SEPARATOR.'.docker-feature-tests'.DIRECTORY_SEPARATOR)
                || str_contains($path, DIRECTORY_SEPARATOR.'.incus-feature-tests'.DIRECTORY_SEPARATOR)
            ) {
                continue;
            }

            if (! str_ends_with($path, 'Test.php')) {
                continue;
            }

            $testFile = str_replace(base_path().'/', '', $path);

            if (! $this->isFeatureTestFileForProvider($testFile, 'incus')) {
                continue;
            }

            $files[] = $testFile;
        }

        sort($files);

        return $this->topologyBalancedTestFiles($files, 'incus');
    }

    /**
     * @param  list<string>  $arguments
     */
    private function hasListTestsArgument(array $arguments): bool
    {
        return in_array('--list-tests', $arguments, true) || in_array('--list-tests-xml', $arguments, true);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function hasExplicitTestPath(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '-')) {
                continue;
            }

            if (is_file(base_path($argument)) || is_dir(base_path($argument))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function explicitTestFiles(array $arguments, string $provider): array
    {
        $files = [];

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '-')) {
                continue;
            }

            $path = base_path($argument);

            if (is_file($path)) {
                $testFile = $this->relativeE2ETestFile($path);

                if ($testFile !== null && $this->isFeatureTestFileForProvider($testFile, $provider)) {
                    $files[$testFile] = $testFile;
                }

                continue;
            }

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                $testFile = $this->relativeE2ETestFile($file->getPathname());

                if ($testFile === null || ! $this->isFeatureTestFileForProvider($testFile, $provider)) {
                    continue;
                }

                $files[$testFile] = $testFile;
            }
        }

        $files = array_values($files);
        sort($files);

        return $this->topologyBalancedTestFiles($files, $provider);
    }

    private function relativeE2ETestFile(string $path): ?string
    {
        $realPath = realpath($path);
        $basePath = realpath(base_path());

        if (
            ! is_string($realPath)
            || ! is_string($basePath)
            || ! str_starts_with($realPath, $basePath.DIRECTORY_SEPARATOR)
        ) {
            return null;
        }

        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($realPath, strlen($basePath) + 1));

        if (
            ! str_starts_with($relative, 'tests/Feature/Commands/')
            || str_contains($relative, '/.docker-feature-tests/')
            || str_contains($relative, '/.incus-feature-tests/')
            || ! str_ends_with($relative, 'Test.php')
        ) {
            return null;
        }

        return $relative;
    }

    /**
     * @return list<string>
     */
    private function dockerTestFiles(): array
    {
        $directory = repo_path('apps/e2e/tests/Feature/Commands');

        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $path = $file->getPathname();

            if (str_contains($path, DIRECTORY_SEPARATOR.'.docker-feature-tests'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (! str_ends_with($path, 'Test.php')) {
                continue;
            }

            $testFile = str_replace(base_path().'/', '', $path);

            if (! $this->isFeatureTestFileForProvider($testFile, 'docker')) {
                continue;
            }

            $files[] = $testFile;
        }

        sort($files);

        return $this->topologyBalancedTestFiles($files, 'docker');
    }

    private function isFeatureTestFileForProvider(string $testFile, string $provider): bool
    {
        $contents = file_get_contents(base_path($testFile));

        if (
            ! is_string($contents)
            || ! str_contains($contents, 'e2e-feature')
            || str_contains($contents, 'e2e-provision')
            || str_contains($contents, 'e2e-topology-contract')
        ) {
            return false;
        }

        return match ($provider) {
            'docker' => ! str_contains($contents, 'e2e-provider-incus'),
            'incus' => str_contains($contents, 'e2e-provider-incus'),
            default => false,
        };
    }

    /**
     * @param  list<string>  $testFiles
     * @return list<string>
     */
    private function topologyBalancedTestFiles(array $testFiles, string $provider): array
    {
        $buckets = [];

        foreach ($testFiles as $testFile) {
            $weight = $this->topologyWeightForTestFile($testFile, $provider);
            $buckets[$weight] ??= [];
            $buckets[$weight][] = $testFile;
        }

        krsort($buckets, SORT_NUMERIC);

        $ordered = [];

        while ($buckets !== []) {
            foreach (array_keys($buckets) as $weight) {
                $next = array_shift($buckets[$weight]);

                if (is_string($next)) {
                    $ordered[] = $next;
                }

                if ($buckets[$weight] === []) {
                    unset($buckets[$weight]);
                }
            }
        }

        return $ordered;
    }

    private function topologyWeightForTestFile(string $testFile, string $provider): int
    {
        $kinds = $this->topologyKindsInTestFile($testFile);

        if ($kinds === []) {
            return 0;
        }

        return max(array_map(
            fn (E2ETopologyKind $kind): int => $this->topologyWeight($kind, $provider),
            $kinds,
        ));
    }

    private function topologyWeight(E2ETopologyKind $kind, string $provider): int
    {
        return match ($provider) {
            'docker' => DockerTopologyProvider::containerCountFor($kind),
            'incus' => count(IncusTopologyTemplate::rolesFor($kind)),
            default => 1,
        };
    }

    /**
     * @return list<E2ETopologyKind>
     */
    private function topologyKindsInTestFile(string $testFile): array
    {
        $contents = file_get_contents(base_path($testFile));

        if (! is_string($contents)) {
            return [];
        }

        $kinds = [];

        foreach (E2ETopologyKind::cases() as $kind) {
            $groups = [
                $kind->featureGroup(),
                ...$kind->deprecatedFeatureGroups(),
            ];

            if (array_any(
                $groups,
                fn (string $group): bool => (
                    preg_match('/(?<![A-Za-z0-9_-])'.preg_quote($group, '/').'(?![A-Za-z0-9_-])/', $contents) === 1
                ),
            )) {
                $kinds[$kind->value] = $kind;
            }
        }

        return array_values($kinds);
    }

    /**
     * @return list<string>
     */
    private function passThroughArguments(): array
    {
        if (! $this->input instanceof ArgvInput) {
            return [];
        }

        $tokens = $this->input->getRawTokens(strip: true);
        $arguments = [];
        $skipNext = false;

        foreach ($tokens as $token) {
            if ($skipNext) {
                $skipNext = false;

                continue;
            }

            if ($token === '--') {
                continue;
            }

            if (in_array(
                $token,
                ['--canary', '--dry-run', '--json', '--sequential-lanes', '--sequential-tests'],
                true,
            )) {
                continue;
            }

            if ($token === '--lanes') {
                $skipNext = true;

                continue;
            }

            if (str_starts_with($token, '--lanes=')) {
                continue;
            }

            $arguments[] = $this->normalizePassThroughArgument($token);
        }

        return $arguments;
    }

    private function normalizePassThroughArgument(string $argument): string
    {
        if (
            $argument === 'apps/e2e/tests/Feature/Commands'
            || str_starts_with($argument, 'apps/e2e/tests/Feature/Commands/')
        ) {
            return substr($argument, strlen('apps/e2e/'));
        }

        return $argument;
    }

    /**
     * @param  array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>  $plans
     */
    private function renderDryRun(array $plans): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(['success' => ['data' => ['lanes' => array_values($plans)]]], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        foreach ($plans as $plan) {
            $this->line("lane: {$plan['lane']}");
            $this->line('command: '.$this->renderCommand($plan['command']));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>  $plans
     * @return array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>
     */
    private function reserveSelectedIncusHostsOutOfDockerPlan(array $plans): array
    {
        if (! isset($plans['docker'], $plans['incus'])) {
            return $plans;
        }

        $reservedHosts = $this->reservedDockerHostsForSelectedIncusLane($plans);

        if ($reservedHosts === []) {
            return $plans;
        }

        $config = E2EConfig::fromEnvironment();
        $capacity = $this->dockerCapacityPlan(
            config: $config,
            testFiles: $plans['docker']['test_files'] ?? [],
            requestedProcesses: $this->dockerRequestedProcessCount($config),
            excludedHosts: $reservedHosts,
        );

        if ($capacity === null) {
            return $plans;
        }

        if (! (bool) $this->option('dry-run')) {
            foreach ($config->dockerHostSlots as $host => $_slots) {
                if (isset($reservedHosts[strtolower($host)])) {
                    $this->line('E2E Docker runner ['.strtolower($host).'] ignored: reserved for selected Incus lane');
                }
            }
        }

        $plans['docker']['environment']['ORBIT_E2E_DOCKER_TEST_RUNNERS'] = $this->renderDockerTestRunners(
            $capacity['host_slots'],
            $config,
        );
        $plans['docker']['environment']['ORBIT_E2E_PARALLEL_PROCESSES'] = (string) $capacity['processes'];
        $plans['docker']['command'] = $this->applyPestProcessCount($plans['docker']['command'], $capacity['processes']);

        return $plans;
    }

    /**
     * @param  array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>}>  $plans
     */
    private function runPlans(array $plans): int
    {
        if ((bool) $this->option('sequential-lanes')) {
            try {
                $plans = $this->preparePlansForExecution($plans);
            } catch (\InvalidArgumentException $exception) {
                return $this->failCommand($exception->getMessage());
            }

            if ($plans === []) {
                return self::SUCCESS;
            }

            return $this->runPlansSequentially($plans);
        }

        $startedAt = microtime(true);

        $this->emitCheckpoint('e2e.lanes', 'started');
        try {
            $plans = $this->preparePlansForExecution($plans);
        } catch (\InvalidArgumentException $exception) {
            $this->emitCheckpoint('e2e.lanes', 'failed', microtime(true) - $startedAt);

            return $this->failCommand($exception->getMessage());
        }

        if ($plans === []) {
            $this->emitCheckpoint('e2e.lanes', 'done', microtime(true) - $startedAt);

            return self::SUCCESS;
        }

        try {
            $this->preparePlanArtifacts($plans);
            $this->registerInterruptHandlers($plans);
        } catch (\Throwable $throwable) {
            $this->runningProcesses = [];
            $this->activePlans = [];
            $this->cleanupPlanArtifacts($plans);

            throw $throwable;
        }

        $laneStartedAt = [];
        $laneFinishedAt = [];

        try {
            foreach ($plans as $lane => $plan) {
                $laneStartedAt[$lane] = microtime(true);
                $this->emitPlanMetadata($plan, laneExecutionMode: 'parallel');
                $this->emitCheckpoint("e2e.lane.{$lane}", 'started');

                $this->runningProcesses[$lane] = Process::path(base_path())
                    ->env($this->executionEnvironment($plan))
                    ->forever()
                    ->start($plan['command']);
            }

            $results = $this->waitForRunningProcesses($laneFinishedAt);
        } finally {
            $this->runningProcesses = [];
            $this->activePlans = [];
            $this->cleanupPlanArtifacts($plans);
        }

        $duration = microtime(true) - $startedAt;

        foreach ($results as $lane => $result) {
            $laneDuration = ($laneFinishedAt[$lane] ?? microtime(true)) - $laneStartedAt[$lane];

            $this->emitCheckpoint("e2e.lane.{$lane}", $result->successful() ? 'done' : 'failed', $laneDuration);
        }

        if ($this->resultsSuccessful($results)) {
            $this->emitCheckpoint('e2e.lanes', 'done', $duration);

            return self::SUCCESS;
        }

        foreach ($results as $lane => $result) {
            if ($result->failed()) {
                $this->error("E2E lane [{$lane}] failed with exit code {$result->exitCode()}.");
            }
        }

        $this->emitCheckpoint('e2e.lanes', 'failed', $duration);

        return self::FAILURE;
    }

    /**
     * @param  array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>  $plans
     * @return array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>
     */
    private function preparePlansForExecution(array $plans): array
    {
        $plans = $this->filterUnavailableDockerRunners($plans);
        $this->failIfUnavailablePlans($plans);

        return $plans;
    }

    /**
     * @param  array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>  $plans
     * @return array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>
     */
    private function filterUnavailableDockerRunners(array $plans): array
    {
        if (! isset($plans['docker'])) {
            return $plans;
        }

        if (($plans['docker']['environment']['ORBIT_E2E_DOCKER_TEST_RUNNERS'] ?? '') === '') {
            return $plans;
        }

        /** @var array{host_slots: array<string, int>, processes: int, minimum_processes: int, test_runners: string, unavailable: array<string, string>} $availability */
        $availability = $this->withPlanEnvironment($plans['docker'], function () use ($plans): array {
            $config = E2EConfig::fromEnvironment();
            $hostSlots = [];
            $unavailable = [];
            $plannedProcesses = $this->envInt('ORBIT_E2E_PARALLEL_PROCESSES', array_sum($config->dockerHostSlots));
            $reservedHosts = $this->reservedDockerHostsForSelectedIncusLane($plans);

            foreach ($config->dockerHostSlots as $host => $slots) {
                if (isset($reservedHosts[strtolower($host)])) {
                    $unavailable[$host] = 'reserved for selected Incus lane';

                    continue;
                }

                $reason = $this->dockerRunnerUnavailableReason($config, $host);

                if ($reason !== null) {
                    $unavailable[$host] = $reason;

                    continue;
                }

                $hostSlots[$host] = $slots;
            }

            return [
                'host_slots' => $hostSlots,
                'processes' => array_sum($hostSlots),
                'minimum_processes' => $this->minimumDockerProcessCapacity($plannedProcesses),
                'test_runners' => $hostSlots === [] ? '' : $this->renderDockerTestRunners($hostSlots, $config),
                'unavailable' => $unavailable,
            ];
        });

        foreach ($availability['unavailable'] as $host => $reason) {
            $this->line("E2E Docker runner [{$host}] ignored: {$reason}");
        }

        if ($availability['host_slots'] === []) {
            $details = implode('; ', array_map(
                fn (string $host, string $reason): string => "{$host}: {$reason}",
                array_keys($availability['unavailable']),
                array_values($availability['unavailable']),
            ));
            $message = 'E2E lane [docker] unavailable: no configured Docker test runner is reachable.';

            if ($details !== '') {
                $message .= " {$details}.";
            }

            $this->emitCheckpoint('e2e.lane.docker', 'failed');

            throw new \InvalidArgumentException($message);
        }

        if ($availability['processes'] < $availability['minimum_processes']) {
            $details = implode('; ', array_map(
                fn (string $host, string $reason): string => "{$host}: {$reason}",
                array_keys($availability['unavailable']),
                array_values($availability['unavailable']),
            ));
            $message = "E2E lane [docker] unavailable: reachable Docker capacity is {$availability['processes']} process(es), below required minimum {$availability['minimum_processes']}.";

            if ($details !== '') {
                $message .= " Unavailable runners: {$details}.";
            }

            $message .= " Restore the configured Docker runners or set ORBIT_E2E_DOCKER_MIN_PROCESSES={$availability['processes']} for an intentionally degraded run.";

            $this->emitCheckpoint('e2e.lane.docker', 'failed');

            throw new \InvalidArgumentException($message);
        }

        $plans['docker']['environment']['ORBIT_E2E_DOCKER_TEST_RUNNERS'] = $availability['test_runners'];
        $plans['docker']['environment']['ORBIT_E2E_PARALLEL_PROCESSES'] = (string) $availability['processes'];
        $plans['docker']['command'] = $this->applyPestProcessCount(
            $plans['docker']['command'],
            $availability['processes'],
        );

        return $plans;
    }

    /**
     * @param  array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>  $plans
     * @return array<string, true>
     */
    private function reservedDockerHostsForSelectedIncusLane(array $plans): array
    {
        if (! isset($plans['incus'])) {
            return [];
        }

        return $this->withPlanEnvironment($plans['incus'], function (): array {
            $config = E2EConfig::fromEnvironment();
            $hosts = [];

            foreach ($config->incusHostCandidates() as $host) {
                $hosts[strtolower($host)] = true;
            }

            return $hosts;
        });
    }

    private function minimumDockerProcessCapacity(int $plannedProcesses): int
    {
        $configuredMinimum = $this->envNonNegativeIntOrNull('ORBIT_E2E_DOCKER_MIN_PROCESSES');

        if ($configuredMinimum !== null) {
            return $configuredMinimum;
        }

        return min(8, $plannedProcesses);
    }

    private function dockerRunnerUnavailableReason(E2EConfig $config, string $host): ?string
    {
        $dockerHost = new DockerHost($config, $host);

        if (! $this->dockerProbeSuccessful($dockerHost, 'command -v docker >/dev/null')) {
            return 'docker command is not available';
        }

        if (! $this->dockerProbeSuccessful($dockerHost, 'docker info >/dev/null')) {
            return 'docker daemon is not reachable';
        }

        return null;
    }

    private function dockerProbeSuccessful(DockerHost $dockerHost, string $command): bool
    {
        try {
            return $dockerHost->run($command, timeoutSeconds: 10)->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private function applyPestProcessCount(array $command, int $processes): array
    {
        $command = array_values(array_filter(
            $command,
            fn (string $argument): bool => $argument !== '--parallel' && ! str_starts_with($argument, '--processes='),
        ));

        if ($processes <= 1 || (bool) $this->option('sequential-tests') || $this->hasListTestsArgument($command)) {
            return $command;
        }

        $command[] = '--parallel';
        $command[] = "--processes={$processes}";

        return $command;
    }

    /**
     * @param  array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>  $plans
     */
    private function failIfUnavailablePlans(array $plans): void
    {
        $reasons = $this->unavailableLaneReasons($plans);

        if ($reasons === []) {
            return;
        }

        foreach (array_keys($reasons) as $lane) {
            $this->emitCheckpoint("e2e.lane.{$lane}", 'failed');
        }

        $messages = [];

        foreach ($reasons as $lane => $reason) {
            $messages[] = $this->unavailableLaneMessage($lane, $reason, $plans[$lane] ?? null);
        }

        throw new \InvalidArgumentException(implode(PHP_EOL, $messages));
    }

    /**
     * @param  array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}|null  $plan
     */
    private function unavailableLaneMessage(string $lane, string $reason, ?array $plan): string
    {
        $message = "E2E lane [{$lane}] unavailable: {$reason}";
        $command = $plan === null ? null : $this->artifactEnsureCommand($lane, $reason, $plan);

        if ($command === null) {
            return $message;
        }

        return $message.PHP_EOL."Targeted artifact command: {$command}";
    }

    /**
     * @param  array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}  $plan
     */
    private function artifactEnsureCommand(string $lane, string $reason, array $plan): ?string
    {
        return match ($lane) {
            'docker' => $this->dockerArtifactEnsureCommand($reason),
            'incus' => $this->incusArtifactEnsureCommand($reason, $plan),
            default => null,
        };
    }

    private function dockerArtifactEnsureCommand(string $reason): ?string
    {
        $lowerReason = strtolower($reason);
        $kind = E2ETopologyKind::OperatorGatewayAppdevAppprodAgent->value;

        if (
            str_contains($lowerReason, 'docker runtime image')
            || str_contains($lowerReason, 'docker orbit-caddy image')
            || str_contains($lowerReason, 'docker php runtime image')
        ) {
            return "composer e2e:ensure-artifacts -- --lanes=docker --runtime --force {$kind}";
        }

        if (! preg_match('/docker prepared image (?P<image>\S+) is not available/i', $reason, $matches)) {
            return null;
        }

        $role = $this->artifactRoleFromDockerImage($matches['image']);

        if ($role === null) {
            return null;
        }

        return "composer e2e:ensure-artifacts -- --lanes=docker --roles={$role} --force {$kind}";
    }

    private function artifactRoleFromDockerImage(string $image): ?string
    {
        foreach (E2EPreparedTopology::artifactRoles() as $role) {
            if (str_contains($image, ":{$role}_")) {
                return $role;
            }
        }

        return null;
    }

    /**
     * @param  array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}  $plan
     */
    private function incusArtifactEnsureCommand(string $reason, array $plan): ?string
    {
        if (
            ! str_contains($reason, 'prepared topology')
            && ! str_contains($reason, 'prepared templates or snapshots')
        ) {
            return null;
        }

        $kind =
            $this->topologyKindFromUnavailableReason($reason) ?? $this->laneRequiredTopologies($plan)[0]
                ?? E2ETopologyKind::OperatorGatewayAppdevAppprodAgent;

        if (E2ETopologyArtifactNamespace::artifactSet() === E2ETopologyArtifactNamespace::BaseArtifactSet) {
            $sourceKind = E2EPreparedTopology::incusSourceKindFor($kind);

            return "composer e2e:prepare-topology -- --force {$sourceKind->value}";
        }

        $roles = array_values(array_unique(array_map(
            E2EPreparedTopology::artifactRoleForIncusRole(...),
            IncusTopologyTemplate::rolesFor($kind),
        )));

        return sprintf(
            'composer e2e:ensure-artifacts -- --lanes=incus --roles=%s %s',
            implode(',', $roles),
            $kind->value,
        );
    }

    private function topologyKindFromUnavailableReason(string $reason): ?E2ETopologyKind
    {
        if (! preg_match('/prepared topology (?P<kind>[a-z0-9_-]+)/i', $reason, $matches)) {
            return null;
        }

        return E2ETopologyKind::tryFromInput($matches['kind']);
    }

    /**
     * @param  array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>}>  $plans
     * @return array<string, string>
     */
    private function unavailableLaneReasons(array $plans): array
    {
        if ($this->laneAvailabilityResolver !== null) {
            return ($this->laneAvailabilityResolver)($plans);
        }

        $reasons = [];

        foreach ($plans as $lane => $plan) {
            $reason = match ($lane) {
                'docker' => $this->laneUnavailableReason($plan, E2ETopologyCapabilities::containerFeature()),
                'incus' => $this->laneUnavailableReason($plan, E2ETopologyCapabilities::vm()),
                default => null,
            };

            if ($reason !== null) {
                $reasons[$lane] = $reason;
            }
        }

        return $reasons;
    }

    /**
     * @param  array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}  $plan
     */
    private function laneUnavailableReason(array $plan, E2ETopologyCapabilities $requirements): ?string
    {
        if (($plan['environment']['ORBIT_E2E_TOPOLOGY_PROVIDERS'] ?? '') === '') {
            return null;
        }

        $requiredTopologies = $this->laneRequiredTopologies($plan);

        if ($requiredTopologies === []) {
            return null;
        }

        return $this->withPlanEnvironment($plan, function () use ($requiredTopologies, $requirements): ?string {
            $config = E2EConfig::fromEnvironment();
            $pool = E2ETopologyProviderPool::fromEnvironment($config);

            foreach ($requiredTopologies as $kind) {
                $selection = $pool->select($kind, $requirements);

                if (! $selection->available()) {
                    return $selection->message;
                }
            }

            return null;
        });
    }

    /**
     * @param  array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}  $plan
     * @return list<E2ETopologyKind>
     */
    private function laneRequiredTopologies(array $plan): array
    {
        return match ($plan['lane']) {
            'docker' => $this->dockerLaneTopologyKinds($plan['test_files'] ?? []),
            'incus' => $this->incusLaneTopologyKinds($plan['test_files'] ?? []),
            default => [],
        };
    }

    /**
     * @template TValue
     *
     * @param  array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}  $plan
     * @param  callable(): TValue  $callback
     * @return TValue
     */
    private function withPlanEnvironment(array $plan, callable $callback): mixed
    {
        $previous = [];

        foreach ($plan['environment'] as $key => $value) {
            $previous[$key] = getenv($key);
            putenv("{$key}={$value}");
        }

        try {
            return $callback();
        } finally {
            foreach ($previous as $key => $value) {
                $value === false ? putenv($key) : putenv("{$key}={$value}");
            }
        }
    }

    /**
     * @param  array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>}>  $plans
     */
    private function runPlansSequentially(array $plans): int
    {
        $failed = false;

        try {
            $this->preparePlanArtifacts($plans);
            $this->registerInterruptHandlers($plans);
        } catch (\Throwable $throwable) {
            $this->runningProcesses = [];
            $this->activePlans = [];
            $this->cleanupPlanArtifacts($plans);

            throw $throwable;
        }

        try {
            foreach ($plans as $lane => $plan) {
                $startedAt = microtime(true);

                $this->emitPlanMetadata($plan, laneExecutionMode: 'sequential');
                $this->emitCheckpoint("e2e.lane.{$lane}", 'started');

                $this->runningProcesses[$lane] = Process::path(base_path())
                    ->env($this->executionEnvironment($plan))
                    ->forever()
                    ->start($plan['command']);

                $result = $this->runningProcesses[$lane]->wait(function (string $type, string $output): void {
                    $this->writeProcessOutput($type, $output);
                });

                unset($this->runningProcesses[$lane]);

                $this->emitCheckpoint(
                    "e2e.lane.{$lane}",
                    $result->successful() ? 'done' : 'failed',
                    microtime(true) - $startedAt,
                );

                if ($result->failed()) {
                    $this->error("E2E lane [{$lane}] failed with exit code {$result->exitCode()}.");
                    $failed = true;
                }
            }
        } finally {
            $this->runningProcesses = [];
            $this->activePlans = [];
            $this->cleanupPlanArtifacts($plans);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, float>  $laneFinishedAt
     * @return array<string, ProcessResult>
     */
    private function waitForRunningProcesses(array &$laneFinishedAt): array
    {
        while (count($laneFinishedAt) < count($this->runningProcesses)) {
            foreach ($this->runningProcesses as $lane => $process) {
                if (array_key_exists($lane, $laneFinishedAt)) {
                    continue;
                }

                $this->flushProcessOutput($process);

                if (! $process->running()) {
                    $this->flushProcessOutput($process);
                    $laneFinishedAt[$lane] = microtime(true);
                }
            }

            if (count($laneFinishedAt) === count($this->runningProcesses)) {
                break;
            }

            usleep(100_000);
        }

        $results = [];

        foreach ($this->runningProcesses as $lane => $process) {
            $results[$lane] = $process->wait(function (string $type, string $output): void {
                $this->writeProcessOutput($type, $output);
            });
            $laneFinishedAt[$lane] ??= microtime(true);
        }

        return $results;
    }

    private function flushProcessOutput(InvokedProcess|FakeInvokedProcess $process): void
    {
        $output = $process->latestOutput();

        if ($output !== '') {
            $this->writeProcessOutput('out', $output);
        }

        $errorOutput = $process->latestErrorOutput();

        if ($errorOutput !== '') {
            $this->writeProcessOutput('err', $errorOutput);
        }
    }

    /**
     * @param  array<string, ProcessResult>  $results
     */
    private function resultsSuccessful(array $results): bool
    {
        return array_all($results, fn ($result) => ! $result->failed());
    }

    /**
     * @param  array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>}>  $plans
     */
    private function registerInterruptHandlers(array $plans): void
    {
        $this->activePlans = $plans;

        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, function (int $signal): void {
                $this->handleInterrupt($signal);
            });
        }
    }

    private function handleInterrupt(int $signal): void
    {
        $exitCode = 128 + $signal;

        if ($this->interruptCleanupStarted) {
            exit($exitCode);
        }

        $this->interruptCleanupStarted = true;
        $this->emitCheckpoint('e2e.interrupt', 'started');

        try {
            foreach ($this->runningProcesses as $process) {
                $process->stop(2, $signal);
            }

            $this->cleanupPlanArtifacts($this->activePlans);
            $this->runInterruptReapers();

            $this->emitCheckpoint('e2e.interrupt', 'done');
        } catch (\Throwable $throwable) {
            $this->emitCheckpoint('e2e.interrupt', 'failed');
            $this->error($throwable->getMessage());
        }

        exit($exitCode);
    }

    private function runInterruptReapers(): void
    {
        foreach ($this->activeReaperCommands() as $lane => $command) {
            $this->emitCheckpoint("e2e.cleanup.{$lane}", 'started');

            $process = Process::path(base_path())
                ->forever();

            $environment = $this->activeReaperEnvironment($lane);

            if ($environment !== []) {
                $process = $process->env([
                    ...$environment,
                    ...$this->githubAuthEnvironment(),
                ]);
            }

            $result = $process->run($command, function (string $type, string $output): void {
                $this->writeProcessOutput($type, $output);
            });

            $this->emitCheckpoint("e2e.cleanup.{$lane}", $result->successful() ? 'done' : 'failed');
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function activeReaperCommands(): array
    {
        $lanes = array_values(array_unique(array_map(
            fn (array $plan): string => $plan['lane'],
            $this->activePlans,
        )));

        $commands = [];

        if (in_array('docker', $lanes, true)) {
            $commands['docker'] = ['php', 'artisan', 'e2e:reap-docker', '--force', '--older-than=0m'];
        }

        if (in_array('incus', $lanes, true)) {
            $commands['incus'] = ['php', 'artisan', 'e2e:reap-incus', '--force', '--older-than=0m'];
        }

        return $commands;
    }

    /**
     * @return array<string, string>
     */
    private function activeReaperEnvironment(string $lane): array
    {
        foreach ($this->activePlans as $plan) {
            if ($plan['lane'] === $lane) {
                return $plan['environment'];
            }
        }

        return [];
    }

    /**
     * @param  array{environment: array<string, string>}  $plan
     * @return array<string, string>
     */
    private function executionEnvironment(array $plan): array
    {
        return [
            ...$plan['environment'],
            ...$this->githubAuthEnvironment(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function githubAuthEnvironment(): array
    {
        $environment = [];

        foreach (['GH_TOKEN', 'GITHUB_TOKEN'] as $key) {
            $value = getenv($key);

            if (! is_string($value)) {
                continue;
            }

            $token = trim($value);

            if ($token === '') {
                continue;
            }

            $environment[$key] = $token;
        }

        return $environment;
    }

    /**
     * @param  array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>  $plans
     */
    private function preparePlanArtifacts(array &$plans): void
    {
        foreach ($plans as &$plan) {
            if ($this->timingsEnabled()) {
                $plan['timings_file'] = $this->createTimingsFile($plan['lane']);
                $plan['environment']['ORBIT_E2E_TIMINGS_FILE'] = $plan['timings_file'];
            }

            $testPath = $plan['test_path'] ?? null;
            $testFiles = $plan['test_files'] ?? [];

            if (! is_string($testPath) || $testFiles === []) {
                continue;
            }

            $directory = base_path($testPath);
            $this->removeDirectory($directory);

            if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
                throw new \RuntimeException("Could not create E2E test suite directory [{$directory}].");
            }

            $supportDirectory = repo_path('apps/e2e/tests/Feature/Commands/Support');

            if (is_dir($supportDirectory)) {
                $generatedSupportDirectory = $directory.'/Support';

                if (! mkdir($generatedSupportDirectory, 0777, true) && ! is_dir($generatedSupportDirectory)) {
                    throw new \RuntimeException(
                        "Could not create E2E support directory [{$generatedSupportDirectory}].",
                    );
                }

                foreach (glob($supportDirectory.'/*.php') ?: [] as $supportFile) {
                    $supportTarget = $generatedSupportDirectory.'/'.basename($supportFile);

                    if (! copy($supportFile, $supportTarget)) {
                        throw new \RuntimeException("Could not copy E2E support file [{$supportFile}].");
                    }
                }
            }

            foreach ($testFiles as $index => $testFile) {
                $target = base_path($testFile);
                $link = $directory.'/Docker'.str_pad((string) $index, 3, '0', STR_PAD_LEFT).basename($testFile);

                if (! copy($target, $link)) {
                    throw new \RuntimeException("Could not copy E2E test file [{$testFile}].");
                }
            }
        }

        unset($plan);
    }

    /**
     * @param  array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>  $plans
     */
    private function cleanupPlanArtifacts(array $plans): void
    {
        foreach ($plans as $plan) {
            $this->replayTimingsFile($plan);

            $testPath = $plan['test_path'] ?? null;

            if (is_string($testPath)) {
                $this->removeDirectory(base_path($testPath));
                @rmdir(dirname(base_path($testPath)));
            }

            $timingsFile = $plan['timings_file'] ?? null;

            if (is_string($timingsFile) && $timingsFile !== '') {
                @unlink($timingsFile);
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isDir() && ! $file->isLink()) {
                rmdir($file->getPathname());

                continue;
            }

            unlink($file->getPathname());
        }

        rmdir($directory);
    }

    private function emitCheckpoint(string $phase, string $status, ?float $seconds = null): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $line = "[orbit-e2e] {$phase} {$status}";

        if ($seconds !== null) {
            $line .= sprintf(' %.3fs', $seconds);
        }

        fwrite(STDERR, "{$line}\n");
    }

    /**
     * @param  array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}  $plan
     */
    private function emitPlanMetadata(array $plan, string $laneExecutionMode): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $metadataFile = getenv('ORBIT_E2E_PLAN_METADATA_FILE');

        if (! is_string($metadataFile) || trim($metadataFile) === '') {
            return;
        }

        $line = $this->planMetadataLine($plan, $laneExecutionMode);

        @file_put_contents(trim($metadataFile), "{$line}\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @param  array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}  $plan
     */
    private function planMetadataLine(array $plan, string $laneExecutionMode): string
    {
        return self::E2E_PLAN_PREFIX
        .json_encode(
            $this->planMetadata($plan, $laneExecutionMode),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}  $plan
     * @return array<string, mixed>
     */
    private function planMetadata(array $plan, string $laneExecutionMode): array
    {
        $commandProcesses = $this->commandProcessCount($plan['command']);
        $environment = $this->planMetadataEnvironment($plan['environment']);
        $metadata = [
            'schema_version' => 1,
            'lane' => $plan['lane'],
            'provider' => $plan['environment']['ORBIT_E2E_TOPOLOGY_PROVIDER'] ?? $plan['lane'],
            'lane_execution_mode' => $laneExecutionMode,
            'test_execution_mode' => $commandProcesses === null ? 'sequential' : 'parallel',
        ];

        if ($commandProcesses !== null) {
            $metadata['command_processes'] = $commandProcesses;
        }

        if (isset($plan['test_files'])) {
            $metadata['test_file_count'] = count($plan['test_files']);
        }

        if ($environment !== []) {
            $metadata['environment'] = $environment;
        }

        return $metadata;
    }

    /**
     * @param  list<string>  $command
     */
    private function commandProcessCount(array $command): ?int
    {
        foreach ($command as $index => $argument) {
            if (str_starts_with($argument, '--processes=')) {
                $value = substr($argument, strlen('--processes='));

                return is_numeric($value) ? (int) $value : null;
            }

            if ($argument === '--processes' && isset($command[$index + 1]) && is_numeric($command[$index + 1])) {
                return (int) $command[$index + 1];
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $environment
     * @return array<string, string>
     */
    private function planMetadataEnvironment(array $environment): array
    {
        $keys = [
            'ORBIT_E2E_PARALLEL_PROCESSES',
            'ORBIT_E2E_DOCKER_TEST_RUNNERS',
            'ORBIT_E2E_DOCKER_MIN_PROCESSES',
            'ORBIT_E2E_INCUS_HOSTS',
            'ORBIT_E2E_INCUS_HOST_VM_CAPS',
            'ORBIT_E2E_INCUS_PARALLEL_PROCESSES',
            'ORBIT_E2E_INCUS_STORAGE_POOL',
        ];
        $metadata = [];

        foreach ($keys as $key) {
            $value = $environment[$key] ?? getenv($key);

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $metadata[$key] = trim($value);
        }

        return $metadata;
    }

    private function writeProcessOutput(string $type, string $output): void
    {
        if ($type === 'err') {
            fwrite(STDERR, $output);

            return;
        }

        $this->output->write($output);
    }

    /**
     * @param  list<string>  $command
     */
    private function renderCommand(array $command): string
    {
        return implode(' ', array_map(escapeshellarg(...), $command));
    }

    private function envInt(string $key, int $default): int
    {
        return $this->envIntOrNull($key) ?? $default;
    }

    private function envIntOrNull(string $key): ?int
    {
        $value = getenv($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return max(1, (int) $value);
    }

    private function envNonNegativeIntOrNull(string $key): ?int
    {
        $value = getenv($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }

    private function failCommand(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    /**
     * @param  array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}  $plan
     */
    private function replayTimingsFile(array $plan): void
    {
        $timingsFile = $plan['timings_file'] ?? null;

        if (! is_string($timingsFile) || $timingsFile === '' || ! is_file($timingsFile)) {
            return;
        }

        $contents = file_get_contents($timingsFile);

        if ($contents === false || $contents === '') {
            return;
        }

        if ($this->output instanceof ConsoleOutputInterface) {
            $this->output->getErrorOutput()->write($contents);

            return;
        }

        fwrite(STDERR, $contents);
    }

    private function timingsEnabled(): bool
    {
        return getenv('ORBIT_E2E_TIMINGS') === '1';
    }

    private function createTimingsFile(string $lane): string
    {
        $path = tempnam(sys_get_temp_dir(), "orbit-e2e-{$lane}-");

        if (! is_string($path)) {
            throw new \RuntimeException("Could not create timings file for E2E lane [{$lane}].");
        }

        return $path;
    }
}
