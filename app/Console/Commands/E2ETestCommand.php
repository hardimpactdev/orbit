<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\DockerTopologyProvider;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyCapabilities;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyProviderPool;
use App\E2E\Support\IncusTopologyTemplate;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

#[Signature('e2e:test
    {--lanes= : Comma-separated lanes to run. Defaults to ORBIT_E2E_LANES or docker,incus}
    {--canary : Restrict the Docker lane to the e2e-feature-canary group only}
    {--sequential-lanes : Run selected lanes one after another}
    {--dry-run : Print the lane commands without executing them}
    {--json : Output dry-run or failure details as JSON}')]
#[Description('Run prepared-topology E2E lanes')]
class E2ETestCommand extends Command
{
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

        if ($message = $this->validateLaneCapacity($plans)) {
            return $this->failCommand($message);
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

        $config = E2EConfig::fromEnvironment();

        if ($config->dockerHostSlots === []) {
            return null;
        }

        $totalSlots = array_sum($config->dockerHostSlots);
        $processes = $this->envInt('ORBIT_E2E_PARALLEL_PROCESSES', 8);

        if ($processes !== $totalSlots) {
            return "ORBIT_E2E_PARALLEL_PROCESSES must match total Docker slots [{$totalSlots}] for the measured Docker lane.";
        }

        $requiredContainers = max($config->dockerHostSlots) * DockerTopologyProvider::maxContainerCountForAnyTopology();

        if ($config->dockerMaxContainersPerHost < $requiredContainers) {
            return "ORBIT_E2E_DOCKER_MAX_CONTAINERS_PER_HOST must be at least {$requiredContainers} for the largest configured Docker host slot count.";
        }

        return null;
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

        $processes = $this->envInt('ORBIT_E2E_PARALLEL_PROCESSES', 8);

        if ($processes > 1 && ! $this->hasListTestsArgument($passThroughArguments)) {
            $command[] = '--parallel';
            $command[] = "--processes={$processes}";
        }

        if (! $this->hasExplicitTestPath($passThroughArguments)) {
            $testPath = 'tests/E2E/.docker-feature-tests/'.$this->dockerTestRunDirectory();
            $testFiles = $this->dockerTestFiles();
            $command[] = $testPath;
        }

        $plan = [
            'lane' => 'docker',
            'command' => [...$command, ...$passThroughArguments],
            'environment' => [
                'ORBIT_E2E' => '1',
                'ORBIT_E2E_TOPOLOGY_PROVIDER' => 'docker',
                'ORBIT_E2E_TOPOLOGY_PROVIDERS' => 'docker',
                'ORBIT_E2E_GATEWAY_API' => '1',
                'ORBIT_E2E_TOPOLOGY_CACHE' => 'process',
                'ORBIT_E2E_CHECKOUT_CACHE' => 'process',
                'ORBIT_E2E_TOPOLOGY_STRATEGY' => 'superset',
            ],
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
            '--exclude-group=e2e-feature-reachability',
        ];

        $processes = $this->incusCachedWorkerCount(E2EConfig::fromEnvironment());

        if ($processes > 1 && ! $this->hasListTestsArgument($passThroughArguments)) {
            $command[] = '--parallel';
            $command[] = "--processes={$processes}";
        }

        if (! $this->hasExplicitTestPath($passThroughArguments)) {
            $testPath = 'tests/E2E/.incus-feature-tests/'.$this->incusTestRunDirectory();
            $testFiles = $this->incusTestFiles();
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
                'ORBIT_E2E_TOPOLOGY_CACHE' => 'process',
                'ORBIT_E2E_CHECKOUT_CACHE' => 'process',
                'ORBIT_E2E_TOPOLOGY_STRATEGY' => 'superset',
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

    private function incusCachedWorkerCount(E2EConfig $config): int
    {
        $requested = $this->envInt('ORBIT_E2E_INCUS_PARALLEL_PROCESSES', 1);
        $supersetSize = count(IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent));
        $capacityBound = intdiv($config->incusMaxVmsPerHost, $supersetSize);

        return max(1, min($requested, $capacityBound));
    }

    /**
     * @return list<string>
     */
    private function incusTestFiles(): array
    {
        $directory = base_path('tests/E2E');

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
                || str_contains($contents, 'e2e-feature-reachability')
                || str_contains($contents, 'e2e-provision')
                || str_contains($contents, 'e2e-topology-contract')
            ) {
                continue;
            }

            $files[] = str_replace(base_path().'/', '', $path);
        }

        sort($files);

        return $files;
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
        $directory = base_path('tests/E2E');

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

        return $files;
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

            if (in_array($token, ['--canary', '--dry-run', '--json', '--sequential-lanes'], true)) {
                continue;
            }

            if ($token === '--lanes') {
                $skipNext = true;

                continue;
            }

            if (str_starts_with($token, '--lanes=')) {
                continue;
            }

            $arguments[] = $token;
        }

        return $arguments;
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
            $plans = $this->skipUnavailablePlans($plans);

            if ($plans === []) {
                return self::SUCCESS;
            }

            return $this->runPlansSequentially($plans);
        }

        $startedAt = microtime(true);

        $this->emitCheckpoint('e2e.lanes', 'started');
        $plans = $this->skipUnavailablePlans($plans);

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

    private function flushProcessOutput(InvokedProcess $process): void
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

            $result = Process::path(base_path())
                ->forever()
                ->run($command, function (string $type, string $output): void {
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

            $supportDirectory = base_path('tests/E2E/Support');

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
        $value = getenv($key);

        if (! is_string($value) || $value === '') {
            return $default;
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
