<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusHostPool;
use App\E2E\Support\IncusTopologyBuilder;
use App\E2E\Support\IncusTopologyTemplate;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use RuntimeException;

#[Signature('e2e:prepare-topology
    {kind=operator_gateway_app-dev_app-prod : Topology kind to prepare (operator|operator_gateway|operator_gateway_app-dev|operator_gateway_app-dev_ingress|operator_gateway_app-dev_websocket|operator_gateway_app-dev_s3|operator_gateway_app-dev_ingress_websocket_s3|operator_gateway_app-dev_app-prod|operator_gateway_app-dev_app-prod_agent|operator_gateway_app-prod_ingress)}
    {--force : Create Incus topology templates}
    {--branch= : Build the source archive from the named git ref via git archive}
    {--source-archive= : Use this pre-built source archive instead of tarring the current checkout}
    {--composer-cache= : Local composer cache directory to ship in the bundle (default ~/.cache/orbit-e2e/composer when present)}
    {--json : Output as JSON}')]
#[Description('Prepare Incus topology templates used by ephemeral E2E tests')]
class E2EPrepareTopologyCommand extends Command
{
    protected $hidden = true;

    /**
     * @var (Closure(IncusHost, E2EPhaseTimer): IncusTopologyBuilder)|null
     */
    private ?Closure $builderFactory = null;

    /**
     * @param  Closure(IncusHost, E2EPhaseTimer): IncusTopologyBuilder  $factory
     */
    public function setBuilderFactory(Closure $factory): void
    {
        $this->builderFactory = $factory;
    }

    public function handle(): int
    {
        $kindValue = (string) $this->argument('kind');

        $kind = E2ETopologyKind::tryFromInput($kindValue);

        if ($kind === null) {
            return $this->failValidation("Invalid topology kind [{$kindValue}]. Supported: operator, operator_gateway, operator_gateway_app-dev, operator_gateway_app-dev_ingress, operator_gateway_app-dev_websocket, operator_gateway_app-dev_s3, operator_gateway_app-dev_ingress_websocket_s3, operator_gateway_app-dev_app-prod, operator_gateway_app-dev_app-prod_agent, operator_gateway_app-prod_ingress. Legacy control and client topology names are accepted as aliases.");
        }

        $config = E2EConfig::fromEnvironment();

        if (! in_array('incus', $config->providerNames, true)) {
            return $this->failCommand('No Incus provider configured. Set ORBIT_E2E_PROVIDER or ORBIT_E2E_PROVIDERS to include incus.');
        }

        $roles = IncusTopologyTemplate::rolesFor($kind);
        $templates = [];

        foreach ($roles as $role) {
            $templates[] = [
                'role' => $role,
                'name' => IncusTopologyTemplate::templateName($kind, $role),
                'snapshot' => IncusTopologyTemplate::snapshotName($kind),
            ];
        }

        $dryRun = ! (bool) $this->option('force');

        if ($dryRun) {
            $result = [
                'provider' => 'incus',
                'dry_run' => true,
                'kind' => $kind->value,
                'templates' => $templates,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode(['success' => ['data' => $result]], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $this->line('Dry run. Pass --force to create Incus topology templates.');

            foreach ($templates as $template) {
                $this->line("planned: {$template['name']} (snapshot: {$template['snapshot']})");
            }

            return self::SUCCESS;
        }

        $hostPool = IncusHostPool::fromEnvironment($config);
        $host = $hostPool->first();

        if ($host === null) {
            return $this->failCommand('No Incus hosts configured. Set ORBIT_E2E_INCUS_HOSTS or ORBIT_E2E_HOST.');
        }

        $bundleDir = null;
        $remoteBundle = null;
        $timer = new E2EPhaseTimer(stream: ! $this->laravel->runningUnitTests());

        try {
            $bundleDir = $timer->measure('bundle.local', fn (): string => $this->buildLocalBundle());
            $remoteBundle = $timer->measure('bundle.push', fn (): string => $host->pushBundle($bundleDir));

            $builder = $this->builderFactory !== null
                ? ($this->builderFactory)($host, $timer)
                : new IncusTopologyBuilder($host, $timer);

            $builder->useBundle($remoteBundle);

            $manifest = $timer->measure('builder.build', fn (): array => $builder->build($kind, replaceExisting: true));
        } catch (RuntimeException $exception) {
            return $this->failCommand($exception->getMessage());
        } finally {
            if ($remoteBundle !== null) {
                $timer->measure('bundle.cleanup.remote', fn () => $host->cleanupBundle($remoteBundle));
            }

            if ($bundleDir !== null && is_dir($bundleDir)) {
                $timer->measure('bundle.cleanup.local', fn () => Process::run('rm -rf '.escapeshellarg((string) $bundleDir)));
            }

            $timer->flush('prepare-topology');
        }

        $result = [
            'provider' => 'incus',
            'dry_run' => false,
            'kind' => $kind->value,
            'templates' => $manifest,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['success' => ['data' => $result]], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("Built topology [{$kind->value}].");

        foreach ($manifest as $template) {
            $this->line("created: {$template['name']} (snapshot: {$template['snapshot']})");
        }

        return self::SUCCESS;
    }

    private function buildLocalBundle(): string
    {
        $bundleDir = sys_get_temp_dir().'/orbit-e2e-bundle-'.bin2hex(random_bytes(6));

        if (! mkdir($bundleDir, 0755, true)) {
            throw new RuntimeException("Could not create local bundle directory: {$bundleDir}");
        }

        $sourceArchive = $this->resolveSourceArchive($bundleDir);
        $finalArchive = "{$bundleDir}/orbit-source.tar.gz";

        if ($sourceArchive !== $finalArchive) {
            if (! @copy($sourceArchive, $finalArchive)) {
                throw new RuntimeException("Could not stage source archive at {$finalArchive}.");
            }
        }

        $this->stageBundleScript($bundleDir, 'install-orbit');
        $this->stageBundleScript($bundleDir, 'e2e-provision-node');
        $this->stageBundleScript($bundleDir, '_e2e-deps.sh');

        $composerCache = $this->resolveComposerCache();

        if ($composerCache !== null) {
            $target = "{$bundleDir}/composer-cache";

            if (! mkdir($target, 0755, true)) {
                throw new RuntimeException("Could not create composer cache target: {$target}");
            }

            $copy = Process::timeout(120)->run(sprintf(
                'cp -R %s %s',
                escapeshellarg(rtrim($composerCache, '/').'/.'),
                escapeshellarg($target),
            ));

            if (! $copy->successful()) {
                throw new RuntimeException("Could not copy composer cache: {$copy->errorOutput()}");
            }
        }

        return $bundleDir;
    }

    private function resolveSourceArchive(string $bundleDir): string
    {
        $explicit = $this->stringOption('source-archive');

        if ($explicit !== null) {
            if (! is_file($explicit)) {
                throw new RuntimeException("--source-archive not found: {$explicit}");
            }

            return $explicit;
        }

        $branch = $this->stringOption('branch');
        $archive = "{$bundleDir}/orbit-source.tar.gz";

        if ($branch !== null) {
            $command = sprintf(
                'git -C %s archive --format=tar.gz --output=%s %s',
                escapeshellarg(base_path()),
                escapeshellarg($archive),
                escapeshellarg($branch),
            );

            $result = Process::timeout(300)->run($command);

            if (! $result->successful()) {
                throw new RuntimeException("git archive failed for branch [{$branch}]: {$result->errorOutput()}");
            }

            return $archive;
        }

        $excludes = [
            './.git',
            './.env',
            './database/*.sqlite',
            './database/*.sqlite-*',
            './node_modules',
            './storage/app/orbit/ca/*',
            './storage/app/orbit/certs/*',
            './storage/app/orbit/keys/*',
            './storage/framework/cache/data/*',
            './storage/framework/sessions/*',
            './storage/framework/testing/*',
            './storage/framework/views/*',
            './storage/logs/*',
            './vendor',
        ];

        $excludeArgs = implode(' ', array_map(
            fn (string $pattern): string => '--exclude='.escapeshellarg($pattern),
            $excludes,
        ));

        $command = sprintf(
            'COPYFILE_DISABLE=1 tar %s -czf %s -C %s .',
            $excludeArgs,
            escapeshellarg($archive),
            escapeshellarg(base_path()),
        );

        $result = Process::timeout(300)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException("Failed to build source archive: {$result->errorOutput()}");
        }

        return $archive;
    }

    private function stageBundleScript(string $bundleDir, string $name): void
    {
        $source = base_path('bin/'.$name);

        if (! is_file($source)) {
            throw new RuntimeException("Required bin script missing: {$source}");
        }

        $target = "{$bundleDir}/{$name}";

        if (! @copy($source, $target)) {
            throw new RuntimeException("Could not stage {$name} into bundle.");
        }

        @chmod($target, 0755);
    }

    private function resolveComposerCache(): ?string
    {
        $explicit = $this->stringOption('composer-cache');

        if ($explicit !== null) {
            if (! is_dir($explicit)) {
                throw new RuntimeException("--composer-cache directory not found: {$explicit}");
            }

            return $explicit;
        }

        $home = (string) (getenv('HOME') ?: '');
        $defaultPath = $home !== '' ? "{$home}/.cache/orbit-e2e/composer" : null;

        if ($defaultPath !== null && is_dir($defaultPath)) {
            return $defaultPath;
        }

        return null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function failValidation(string $message): int
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

    private function failCommand(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'topology_prepare_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }
}
