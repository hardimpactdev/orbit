<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\DockerHost;
use App\E2E\Support\DockerTopologyNetworkPlan;
use App\E2E\Support\DockerTopologyProvider;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyArtifactNamespace;
use App\E2E\Support\E2ETopologyCapabilities;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyProviderPool;
use App\E2E\Support\IncusTopologyTemplate;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\FakeInvokedProcess;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
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

    public function handle(): int
    {
        $passThroughArguments = $this->passThroughArguments();

        try {
            $plans = $this->lanePlans(
                lanes: $this->selectedLanes(),
                passThroughArguments: $passThroughArguments,
            );
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

        return $this->runPlans($plans);
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
            throw new \InvalidArgumentException('Unsupported E2E lane(s): '.implode(', ', $unsupported).'. Supported lanes: docker, incus.');
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
        if (! isset($plans['docker'])) {
            return null;
        }

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
     * @param  list<string>  $passThroughArguments
     * @return array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}
     */
    private function dockerPlan(array $passThroughArguments): array
    {
        $testPath = null;
        $testFiles = [];
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

        if (! $this->hasExplicitTestPath($passThroughArguments)) {
            $testPath = 'apps/gateway/tests/E2E/.docker-feature-tests/'.$this->dockerTestRunDirectory();
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
            $environment['ORBIT_E2E_DOCKER_TEST_RUNNERS'] = $this->renderDockerTestRunners($capacity['host_slots'], $config);
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
        $command = [
            'php',
            'artisan',
            'test',
            '--testsuite=E2E',
            '--group=e2e-provider-incus',
            '--exclude-group=e2e-provision',
            '--exclude-group=e2e-topology-contract',
        ];

        if (! $this->hasExplicitTestPath($passThroughArguments)) {
            $testPath = 'apps/gateway/tests/E2E/.incus-feature-tests/'.$this->incusTestRunDirectory();
            $testFiles = $this->incusTestFiles();
        }

        $processes = $this->incusCachedWorkerCount(E2EConfig::fromEnvironment(), $testFiles);

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
                'ORBIT_E2E_TOPOLOGY_CACHE' => $this->topologyCache(),
                'ORBIT_E2E_CHECKOUT_CACHE' => 'process',
                ...$this->topologyCacheLimitEnvironment(),
                ...$this->topologyArtifactEnvironment(),
                ...$this->runtimeIsolationEnvironment(),
            ],
        ];

        if ($testPath !== null) {
            $plan['test_path'] = $testPath;
            $plan['test_files'] = $testFiles;
        }

        return $plan;
    }

    private function incusTestRunDirectory(): string
    {
        return 'run_'.getmypid().'_'.bin2hex(random_bytes(4));
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
     * @return array{host_slots: array<string, int>, processes: int}|null
     */
    private function dockerCapacityPlan(E2EConfig $config, array $testFiles, int $requestedProcesses): ?array
    {
        if ($config->dockerHostSlots === []) {
            return null;
        }

        $containersPerWorker = $this->dockerLaneMaxContainerCount($testFiles);
        $hostSlots = [];

        foreach ($config->dockerHostSlots as $host => $slots) {
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
        return $processes > 1
            && ! (bool) $this->option('sequential-tests')
            && ! $this->hasListTestsArgument($passThroughArguments);
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

    /**
     * @param  list<string>  $testFiles
     */
    private function incusCachedWorkerCount(E2EConfig $config, array $testFiles): int
    {
        $requested = $this->envInt('ORBIT_E2E_INCUS_PARALLEL_PROCESSES', 1);
        $topologySize = $this->incusLaneMaxVmCount($testFiles);
        $capacityBound = array_sum(array_map(
            fn (string $host): int => intdiv($config->incusMaxVmsForHost($host), $topologySize),
            $config->incusHostCandidates(),
        ));

        return max(1, min($requested, max(1, $capacityBound)));
    }

    /**
     * @param  list<string>  $testFiles
     */
    private function incusLaneMaxVmCount(array $testFiles): int
    {
        $kinds = $this->incusLaneTopologyKinds($testFiles);

        if ($kinds === []) {
            return count(IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent));
        }

        return max(array_map(
            static fn (E2ETopologyKind $kind): int => count(IncusTopologyTemplate::rolesFor($kind)),
            $kinds,
        ));
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
        $directory = base_path('apps/gateway/tests/E2E');

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

            if (str_contains($path, DIRECTORY_SEPARATOR.'.docker-feature-tests'.DIRECTORY_SEPARATOR)
                || str_contains($path, DIRECTORY_SEPARATOR.'.incus-feature-tests'.DIRECTORY_SEPARATOR)
            ) {
                continue;
            }

            if (! str_ends_with($path, 'Test.php')) {
                continue;
            }

            $contents = file_get_contents($path);

            if (! is_string($contents)
                || ! str_contains($contents, 'e2e-provider-incus')
                || str_contains($contents, 'e2e-provision')
                || str_contains($contents, 'e2e-topology-contract')
            ) {
                continue;
            }

            $files[] = str_replace(base_path().'/', '', $path);
        }

        sort($files);

        return $this->topologyBalancedTestFiles($files, 'incus');
    }

    /**
     * @param  list<string>  $arguments
     */
    private function hasListTestsArgument(array $arguments): bool
    {
        return in_array('--list-tests', $arguments, true)
            || in_array('--list-tests-xml', $arguments, true);
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
     * @return list<string>
     */
    private function dockerTestFiles(): array
    {
        $directory = base_path('apps/gateway/tests/E2E');

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

            $contents = file_get_contents($path);

            if (! is_string($contents)
                || ! str_contains($contents, 'e2e-feature')
                || str_contains($contents, 'e2e-provider-incus')
                || str_contains($contents, 'e2e-provision')
                || str_contains($contents, 'e2e-topology-contract')
            ) {
                continue;
            }

            $files[] = str_replace(base_path().'/', '', $path);
        }

        sort($files);

        return $this->topologyBalancedTestFiles($files, 'docker');
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

            if (array_any($groups, fn (string $group): bool => str_contains($contents, $group))) {
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

            if (in_array($token, ['--canary', '--dry-run', '--json', '--sequential-lanes', '--sequential-tests'], true)) {
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
        if ($argument === 'tests/E2E' || str_starts_with($argument, 'tests/E2E/')) {
            return 'apps/gateway/'.$argument;
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
     * @param  array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>}>  $plans
     */
    private function runPlans(array $plans): int
    {
        if ((bool) $this->option('sequential-lanes')) {
            $plans = $this->preparePlansForExecution($plans);

            if ($plans === []) {
                return self::SUCCESS;
            }

            return $this->runPlansSequentially($plans);
        }

        $startedAt = microtime(true);

        $this->emitCheckpoint('e2e.lanes', 'started');
        $plans = $this->preparePlansForExecution($plans);

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
                $this->emitCheckpoint("e2e.lane.{$lane}", 'started');

                $this->runningProcesses[$lane] = Process::path(base_path())
                    ->env($plan['environment'])
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

        return $this->skipUnavailablePlans($plans);
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

        /** @var array{host_slots: array<string, int>, processes: int, test_runners: string, unavailable: array<string, string>} $availability */
        $availability = $this->withPlanEnvironment($plans['docker'], function (): array {
            $config = E2EConfig::fromEnvironment();
            $hostSlots = [];
            $unavailable = [];

            foreach ($config->dockerHostSlots as $host => $slots) {
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
                'test_runners' => $hostSlots === [] ? '' : $this->renderDockerTestRunners($hostSlots, $config),
                'unavailable' => $unavailable,
            ];
        });

        foreach ($availability['unavailable'] as $host => $reason) {
            $this->line("E2E Docker runner [{$host}] ignored: {$reason}");
        }

        if ($availability['host_slots'] === []) {
            $this->emitCheckpoint('e2e.lane.docker', 'skipped');
            $this->line('E2E lane [docker] skipped: no configured Docker test runner is reachable.');
            unset($plans['docker']);

            return $plans;
        }

        $plans['docker']['environment']['ORBIT_E2E_DOCKER_TEST_RUNNERS'] = $availability['test_runners'];
        $plans['docker']['environment']['ORBIT_E2E_PARALLEL_PROCESSES'] = (string) $availability['processes'];
        $plans['docker']['command'] = $this->applyPestProcessCount($plans['docker']['command'], $availability['processes']);

        return $plans;
    }

    private function dockerRunnerUnavailableReason(E2EConfig $config, string $host): ?string
    {
        $dockerHost = new DockerHost($config, $host);

        if (! $dockerHost->run('command -v docker >/dev/null', timeoutSeconds: 10)->successful()) {
            return 'docker command is not available';
        }

        if (! $dockerHost->run('docker info >/dev/null', timeoutSeconds: 10)->successful()) {
            return 'docker daemon is not reachable';
        }

        return null;
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private function applyPestProcessCount(array $command, int $processes): array
    {
        $command = array_values(array_filter(
            $command,
            fn (string $argument): bool => $argument !== '--parallel'
                && ! str_starts_with($argument, '--processes='),
        ));

        if ($processes <= 1
            || (bool) $this->option('sequential-tests')
            || $this->hasListTestsArgument($command)
        ) {
            return $command;
        }

        $command[] = '--parallel';
        $command[] = "--processes={$processes}";

        return $command;
    }

    /**
     * @param  array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>  $plans
     * @return array<string, array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}>
     */
    private function skipUnavailablePlans(array $plans): array
    {
        foreach ($this->unavailableLaneReasons($plans) as $lane => $reason) {
            $this->emitCheckpoint("e2e.lane.{$lane}", 'skipped');
            $this->line("E2E lane [{$lane}] skipped: {$reason}");
            unset($plans[$lane]);
        }

        return $plans;
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
            if ($lane === 'incus') {
                $reason = $this->incusLaneUnavailableReason($plan);

                if ($reason !== null) {
                    $reasons[$lane] = $reason;
                }
            }
        }

        return $reasons;
    }

    /**
     * @param  array{lane: string, command: list<string>, environment: array<string, string>, test_path?: string, test_files?: list<string>, timings_file?: string}  $plan
     */
    private function incusLaneUnavailableReason(array $plan): ?string
    {
        return $this->withPlanEnvironment($plan, function (): ?string {
            $config = E2EConfig::fromEnvironment();
            $pool = E2ETopologyProviderPool::fromEnvironment($config);
            $requirements = E2ETopologyCapabilities::vm();

            foreach ($this->incusLaneRequiredTopologies() as $kind) {
                $selection = $pool->select($kind, $requirements);

                if (! $selection->available()) {
                    return $selection->message;
                }
            }

            return null;
        });
    }

    /**
     * @return list<E2ETopologyKind>
     */
    private function incusLaneRequiredTopologies(): array
    {
        return [
            E2ETopologyKind::ControlGateway,
            E2ETopologyKind::ControlGatewayDev,
        ];
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

                $this->emitCheckpoint("e2e.lane.{$lane}", 'started');

                $this->runningProcesses[$lane] = Process::path(base_path())
                    ->env($plan['environment'])
                    ->forever()
                    ->start($plan['command']);

                $result = $this->runningProcesses[$lane]->wait(function (string $type, string $output): void {
                    $this->writeProcessOutput($type, $output);
                });

                unset($this->runningProcesses[$lane]);

                $this->emitCheckpoint("e2e.lane.{$lane}", $result->successful() ? 'done' : 'failed', microtime(true) - $startedAt);

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
                $process = $process->env($environment);
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

            $supportDirectory = base_path('apps/gateway/tests/E2E/Support');

            if (is_dir($supportDirectory)) {
                $generatedSupportDirectory = $directory.'/Support';

                if (! mkdir($generatedSupportDirectory, 0777, true) && ! is_dir($generatedSupportDirectory)) {
                    throw new \RuntimeException("Could not create E2E support directory [{$generatedSupportDirectory}].");
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
