<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\DockerTopologyProvider;
use App\E2E\Support\E2EArtifactBuildFingerprint;
use App\E2E\Support\E2EArtifactProdManifest;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2EPreparedTopology;
use App\E2E\Support\E2ETopologyArtifactNamespace;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusHostPool;
use App\E2E\Support\IncusTopologyBuilder;
use App\E2E\Support\IncusTopologyTemplate;
use App\E2E\Support\OrbitCliBinaryBundle;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

#[Signature('e2e:prepare-topology
    {kind=operator_gateway_app-dev_app-prod_agent : Topology kind to prepare (operator|operator_gateway|operator_gateway_app-dev|operator_gateway_app-dev_app-prod|operator_gateway_app-dev_app-prod_ingress|operator_gateway_agent|operator_gateway_app-dev_app-prod_agent|operator_gateway_app-prod_ingress|operator_gateway_app-dev_websocket|operator_gateway_app-dev_app-prod_websocket|operator_gateway_app-dev_app-prod_agent_websocket)}
    {--force : Create Incus topology templates}
    {--branch= : Build the source archive from the named git ref via git archive}
    {--source-archive= : Use this pre-built source archive instead of tarring the current checkout}
    {--composer-cache= : Local composer cache directory to ship in the bundle (default ~/.cache/orbit-e2e/composer when present)}
    {--roles= : Comma-separated prepared artifact roles to build (operator,gateway,app-dev,app-prod,ingress,agent,websocket)}
    {--all-roles : Explicitly build every prepared artifact role when a custom namespace is set}
    {--use-build-artifacts : Build topology templates from CLI/gateway build artifacts instead of the synced source checkout}
    {--json : Output as JSON}')]
#[Description('Prepare Incus topology templates used by ephemeral E2E tests')]
class E2EPrepareTopologyCommand extends Command
{
    #[\Override]
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
            return $this->failValidation("Invalid topology kind [{$kindValue}]. Supported: ".E2EPreparedTopology::supportedKindsForHelp().'.');
        }

        $config = E2EConfig::fromEnvironment();

        if (! in_array('incus', $config->providerNames, true)) {
            return $this->failCommand('No Incus provider configured. Set ORBIT_E2E_PROVIDER or ORBIT_E2E_PROVIDERS to include incus.');
        }

        if (! E2EPreparedTopology::supportsKind($kind)) {
            return $this->failValidation(E2EPreparedTopology::unsupportedKindMessage($kind));
        }

        try {
            $artifactRoles = $this->selectedArtifactRoles();
        } catch (InvalidArgumentException $exception) {
            return $this->failValidation($exception->getMessage());
        }

        $useBuildArtifacts = (bool) $this->option('use-build-artifacts');

        $buildKind = E2EPreparedTopology::incusSourceKindFor($kind);
        $requestedRoles = IncusTopologyTemplate::rolesFor($kind);
        $sourceRoles = $this->sourceRolesFor($buildKind, $artifactRoles);

        if ($sourceRoles === []) {
            return $this->failValidation("Selected roles are not prepared by Incus topology source [{$kind->value}].");
        }

        $templates = [];

        foreach ($sourceRoles as $role) {
            $templates[] = [
                'role' => $role,
                'name' => IncusTopologyTemplate::templateName($buildKind, $role),
                'snapshot' => IncusTopologyTemplate::snapshotName($buildKind),
            ];
        }

        $dryRun = ! (bool) $this->option('force');

        if ($dryRun) {
            $result = [
                'provider' => 'incus',
                'dry_run' => true,
                'kind' => $kind->value,
                'source_kind' => $buildKind->value,
                'requested_roles' => $requestedRoles,
                'source_roles' => $sourceRoles,
                'templates' => $templates,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode(['success' => ['data' => $result]], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $this->line('Dry run. Pass --force to create Incus topology templates.');
            $this->line("requested topology: {$kind->value}");
            $this->line('requested roles: '.implode(', ', $requestedRoles));

            if ($buildKind !== $kind) {
                $this->line("source topology: {$buildKind->value}");
                $this->line('source roles: '.implode(', ', $sourceRoles));
            }

            foreach ($templates as $template) {
                $this->line("planned: {$template['name']} (snapshot: {$template['snapshot']})");
            }

            return self::SUCCESS;
        }

        if (! $useBuildArtifacts && $artifactRoles !== null) {
            return $this->failValidation('Selected Incus role rebakes use build artifacts. Pass --use-build-artifacts with --roles or --all-roles.');
        }

        if (! $useBuildArtifacts && ($this->option('branch') !== null || $this->option('source-archive') !== null || $this->option('composer-cache') !== null)) {
            return $this->failValidation('--branch, --source-archive, and --composer-cache only apply with --use-build-artifacts.');
        }

        $hostPool = IncusHostPool::fromEnvironment($config);
        $host = $hostPool->first();

        if ($host === null) {
            return $this->failCommand('No Incus hosts configured. Set ORBIT_E2E_INCUS_HOSTS or ORBIT_E2E_HOST.');
        }

        $bundleDir = null;
        $remoteBundle = null;
        $gatewayArtifactBundleDir = null;
        $remoteGatewayArtifactBundle = null;
        $timer = new E2EPhaseTimer(stream: ! $this->laravel->runningUnitTests());

        try {
            $builder = $this->builderFactory !== null
                ? ($this->builderFactory)($host, $timer)
                : new IncusTopologyBuilder($host, $timer);

            if ($useBuildArtifacts) {
                $bundleDir = $timer->measure('bundle.local', fn (): string => $this->buildLocalBundle());
                $remoteBundle = $timer->measure('bundle.push', fn (): string => $host->pushBundle($bundleDir));

                $builder->useBundle($remoteBundle);
            } else {
                $gatewayArtifactBundleDir = $timer->measure('gateway-artifacts.local', fn (): string => $this->buildLocalGatewayArtifactBundle($timer));
                $remoteGatewayArtifactBundle = $timer->measure('gateway-artifacts.push', fn (): string => $this->pushLocalGatewayArtifactBundle($host, $gatewayArtifactBundleDir, $timer));

                $builder->useGatewayArtifactBundle($remoteGatewayArtifactBundle);
            }

            if ($artifactRoles !== null) {
                $incusRoles = array_map(
                    E2EPreparedTopology::incusRoleForArtifactRole(...),
                    $sourceRoles,
                );
                $incusRoles = array_values(array_unique($incusRoles));

                $manifest = $timer->measure('builder.build', fn (): array => $builder->buildSelectedRoles($buildKind, $incusRoles, replaceExisting: true));
            } else {
                $manifest = $timer->measure('builder.build', fn (): array => $builder->build($buildKind, replaceExisting: true));
            }
        } catch (RuntimeException $exception) {
            return $this->failCommand($exception->getMessage());
        } finally {
            if ($remoteBundle !== null) {
                $timer->measure('bundle.cleanup.remote', fn () => $host->cleanupBundle($remoteBundle));
            }

            if ($remoteGatewayArtifactBundle !== null) {
                $timer->measure('gateway-artifacts.cleanup.remote', fn () => $this->cleanupRemoteCliBinaryBundle($host, $remoteGatewayArtifactBundle));
            }

            if ($bundleDir !== null && is_dir($bundleDir)) {
                $timer->measure('bundle.cleanup.local', fn () => Process::run('rm -rf '.escapeshellarg((string) $bundleDir)));
            }

            if ($gatewayArtifactBundleDir !== null && is_dir($gatewayArtifactBundleDir)) {
                $timer->measure('gateway-artifacts.cleanup.local', fn () => Process::run('rm -rf '.escapeshellarg((string) $gatewayArtifactBundleDir)));
            }

            $timer->flush('prepare-topology');
        }

        $result = [
            'provider' => 'incus',
            'dry_run' => false,
            'kind' => $kind->value,
            'source_kind' => $buildKind->value,
            'requested_roles' => $requestedRoles,
            'source_roles' => $sourceRoles,
            'templates' => $manifest,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['success' => ['data' => $result]], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("Built topology [{$kind->value}].");
        $this->line("source topology: {$buildKind->value}");

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

        // Build and bundle the linux x64 orbit binary so the VM does not need
        // gh/GH_TOKEN for the CLI binary download step during provision. This is
        // a heavy real app:build + phpacker step; skip it under unit tests,
        // which fake Process and only assert command/host selection behavior.
        if (! app()->runningUnitTests()) {
            (new OrbitCliBinaryBundle)->buildLinuxBinaryInto($bundleDir, E2EArtifactBuildFingerprint::cliBinary());
        }

        return $bundleDir;
    }

    private function buildLocalGatewayArtifactBundle(E2EPhaseTimer $timer): string
    {
        $bundleDir = sys_get_temp_dir().'/orbit-e2e-gateway-artifacts-'.bin2hex(random_bytes(6));

        if (! mkdir($bundleDir, 0755, true)) {
            throw new RuntimeException("Could not create local gateway artifact bundle directory: {$bundleDir}");
        }

        if (! app()->runningUnitTests()) {
            $fingerprint = $timer->measure('gateway-artifacts.local.cli-fingerprint', fn (): string => E2EArtifactBuildFingerprint::cliBinary());

            $timer->measure(
                'gateway-artifacts.local.cli-binary',
                fn () => (new OrbitCliBinaryBundle)->buildLinuxBinaryInto($bundleDir, $fingerprint),
            );
        }

        return $bundleDir;
    }

    private function pushLocalGatewayArtifactBundle(IncusHost $host, string $bundleDir, E2EPhaseTimer $timer): string
    {
        $binary = OrbitCliBinaryBundle::bundledBinaryPath($bundleDir);

        if (! app()->runningUnitTests() && ! is_file($binary)) {
            throw new RuntimeException("Gateway artifact bundle is missing: {$binary}");
        }

        $stage = $timer->measure(
            'gateway-artifacts.push.remote-dir',
            fn (): ProcessResult => $host->run('mktemp -d /tmp/orbit-e2e-gateway-artifacts-XXXXXX', timeoutSeconds: 30),
        );

        if (! $stage->successful()) {
            throw new RuntimeException("Could not create remote gateway artifact bundle directory on {$host->config->host}: {$stage->errorOutput()}");
        }

        $remoteDir = trim($stage->output());

        if ($remoteDir === '') {
            throw new RuntimeException("Remote gateway artifact bundle directory was empty on {$host->config->host}.");
        }

        if (app()->runningUnitTests()) {
            return $remoteDir;
        }

        try {
            $copy = $timer->measure('gateway-artifacts.push.binary', fn (): ProcessResult => Process::timeout(120)->run(sprintf(
                'scp -q %s %s',
                escapeshellarg($binary),
                escapeshellarg("{$host->config->host}:{$remoteDir}/orbit-binary"),
            )));

            if (! $copy->successful()) {
                throw new RuntimeException("Could not push gateway artifact bundle to {$host->config->host}:{$remoteDir}: {$copy->errorOutput()}");
            }

            $this->buildRemoteGatewayImageArtifact($host, $remoteDir, E2EArtifactBuildFingerprint::gatewayImage(), $timer);
            $this->buildRemoteWebSocketImageArtifact($host, $remoteDir, E2EArtifactBuildFingerprint::webSocketImage(), $timer);
        } catch (RuntimeException $exception) {
            $host->run('rm -rf '.escapeshellarg($remoteDir).' || true', timeoutSeconds: 30);

            throw $exception;
        }

        return $remoteDir;
    }

    private function buildRemoteGatewayImageArtifact(IncusHost $host, string $remoteDir, string $fingerprint, E2EPhaseTimer $timer): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $archive = "{$remoteDir}/".E2EArtifactProdManifest::GatewayImageArchive;
        $cacheArchive = ".cache/orbit-e2e/gateway-images/{$fingerprint}/".E2EArtifactProdManifest::GatewayImageArchive;
        $cacheCheck = $timer->measure(
            'gateway-artifacts.push.image-cache-check',
            fn (): ProcessResult => $host->run('test -f '.escapeshellarg($cacheArchive), timeoutSeconds: 30),
        );

        if ($cacheCheck->successful()) {
            $cacheCopy = $timer->measure(
                'gateway-artifacts.push.image-cache-copy',
                fn (): ProcessResult => $host->run(sprintf(
                    'cp %s %s && chmod 0644 %s',
                    escapeshellarg($cacheArchive),
                    escapeshellarg($archive),
                    escapeshellarg($archive),
                ), timeoutSeconds: 300),
            );

            if (! $cacheCopy->successful()) {
                throw new RuntimeException("Could not copy cached gateway image archive on {$host->config->host}: {$cacheCopy->errorOutput()}");
            }

            return;
        }

        $remoteBuildContext = "{$remoteDir}/gateway-build-context";

        $mkdir = $timer->measure(
            'gateway-artifacts.push.context-dir',
            fn (): ProcessResult => $host->run('mkdir -p '.escapeshellarg($remoteBuildContext), timeoutSeconds: 30),
        );

        if (! $mkdir->successful()) {
            throw new RuntimeException("Could not create remote gateway image build context on {$host->config->host}: {$mkdir->errorOutput()}");
        }

        foreach (['apps/gateway', 'packages/core', 'docker/orbit-gateway'] as $path) {
            $label = str_replace('/', '-', $path);
            $mkdirParent = $timer->measure(
                "gateway-artifacts.push.context-parent.{$label}",
                fn (): ProcessResult => $host->run('mkdir -p '.escapeshellarg("{$remoteBuildContext}/".dirname($path)), timeoutSeconds: 30),
            );

            if (! $mkdirParent->successful()) {
                throw new RuntimeException("Could not create remote gateway image build context parent for {$path} on {$host->config->host}: {$mkdirParent->errorOutput()}");
            }

            $copy = $timer->measure("gateway-artifacts.push.context-rsync.{$label}", fn (): ProcessResult => Process::timeout(300)->run(sprintf(
                'rsync -a --delete --include=.env.example --exclude=.env --exclude=.env.* --exclude=vendor --exclude=node_modules %s %s',
                escapeshellarg(repo_path($path).'/'),
                escapeshellarg("{$host->config->host}:{$remoteBuildContext}/{$path}/"),
            )));

            if (! $copy->successful()) {
                throw new RuntimeException("Could not stage {$path} on {$host->config->host}: {$copy->errorOutput()}");
            }
        }

        $gatewayImage = DockerTopologyProvider::gatewayImage();
        $build = $timer->measure('gateway-artifacts.push.image-build', fn (): ProcessResult => $host->run(sprintf(
            <<<'BASH'
cd %1$s
docker build -f docker/orbit-gateway/Dockerfile -t %2$s .
BASH,
            escapeshellarg($remoteBuildContext),
            escapeshellarg($gatewayImage),
        ), timeoutSeconds: 1800));

        if (! $build->successful()) {
            throw new RuntimeException("Could not build {$gatewayImage} on {$host->config->host}: {$build->output()}{$build->errorOutput()}");
        }

        $save = $timer->measure('gateway-artifacts.push.image-save', fn (): ProcessResult => $host->run(sprintf(
            <<<'BASH'
docker save %2$s -o %3$s
chmod 0644 %3$s
BASH,
            escapeshellarg($remoteBuildContext),
            escapeshellarg($gatewayImage),
            escapeshellarg($archive),
        ), timeoutSeconds: 1800));

        if (! $save->successful()) {
            throw new RuntimeException("Could not save {$gatewayImage} on {$host->config->host}: {$save->output()}{$save->errorOutput()}");
        }

        $cacheWrite = $timer->measure(
            'gateway-artifacts.push.image-cache-write',
            fn (): ProcessResult => $host->run(sprintf(
                'mkdir -p %s && cp %s %s && chmod 0644 %s',
                escapeshellarg(dirname($cacheArchive)),
                escapeshellarg($archive),
                escapeshellarg($cacheArchive),
                escapeshellarg($cacheArchive),
            ), timeoutSeconds: 300),
        );

        if (! $cacheWrite->successful()) {
            throw new RuntimeException("Could not cache {$gatewayImage} on {$host->config->host}: {$cacheWrite->errorOutput()}");
        }

        $cleanup = $timer->measure(
            'gateway-artifacts.push.context-cleanup',
            fn (): ProcessResult => $host->run('rm -rf '.escapeshellarg($remoteBuildContext), timeoutSeconds: 30),
        );

        if (! $cleanup->successful()) {
            throw new RuntimeException("Could not clean remote gateway image build context on {$host->config->host}: {$cleanup->errorOutput()}");
        }
    }

    private function buildRemoteWebSocketImageArtifact(IncusHost $host, string $remoteDir, string $fingerprint, E2EPhaseTimer $timer): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $archive = "{$remoteDir}/".E2EArtifactProdManifest::WebSocketImageArchive;
        $cacheArchive = ".cache/orbit-e2e/websocket-images/{$fingerprint}/".E2EArtifactProdManifest::WebSocketImageArchive;
        $cacheCheck = $timer->measure(
            'gateway-artifacts.push.websocket-cache-check',
            fn (): ProcessResult => $host->run('test -f '.escapeshellarg($cacheArchive), timeoutSeconds: 30),
        );

        if ($cacheCheck->successful()) {
            $cacheCopy = $timer->measure(
                'gateway-artifacts.push.websocket-cache-copy',
                fn (): ProcessResult => $host->run(sprintf(
                    'cp %s %s && chmod 0644 %s',
                    escapeshellarg($cacheArchive),
                    escapeshellarg($archive),
                    escapeshellarg($archive),
                ), timeoutSeconds: 300),
            );

            if (! $cacheCopy->successful()) {
                throw new RuntimeException("Could not copy cached websocket image archive on {$host->config->host}: {$cacheCopy->errorOutput()}");
            }

            return;
        }

        $remoteBuildContext = "{$remoteDir}/websocket-build-context";

        $mkdir = $timer->measure(
            'gateway-artifacts.push.websocket-context-dir',
            fn (): ProcessResult => $host->run('mkdir -p '.escapeshellarg($remoteBuildContext), timeoutSeconds: 30),
        );

        if (! $mkdir->successful()) {
            throw new RuntimeException("Could not create remote websocket image build context on {$host->config->host}: {$mkdir->errorOutput()}");
        }

        foreach (['apps/reverb', 'docker/orbit-reverb'] as $path) {
            $label = str_replace('/', '-', $path);
            $mkdirParent = $timer->measure(
                "gateway-artifacts.push.websocket-context-parent.{$label}",
                fn (): ProcessResult => $host->run('mkdir -p '.escapeshellarg("{$remoteBuildContext}/".dirname($path)), timeoutSeconds: 30),
            );

            if (! $mkdirParent->successful()) {
                throw new RuntimeException("Could not create remote websocket image build context parent for {$path} on {$host->config->host}: {$mkdirParent->errorOutput()}");
            }

            $copy = $timer->measure("gateway-artifacts.push.websocket-context-rsync.{$label}", fn (): ProcessResult => Process::timeout(300)->run(sprintf(
                'rsync -a --delete --exclude=.env --exclude=.env.* --exclude=vendor --exclude=node_modules %s %s',
                escapeshellarg(repo_path($path).'/'),
                escapeshellarg("{$host->config->host}:{$remoteBuildContext}/{$path}/"),
            )));

            if (! $copy->successful()) {
                throw new RuntimeException("Could not stage {$path} on {$host->config->host}: {$copy->errorOutput()}");
            }
        }

        $webSocketImage = DockerTopologyProvider::webSocketRuntimeImage();
        $build = $timer->measure('gateway-artifacts.push.websocket-build', fn (): ProcessResult => $host->run(sprintf(
            <<<'BASH'
cd %1$s
docker build -f docker/orbit-reverb/Dockerfile -t %2$s .
BASH,
            escapeshellarg($remoteBuildContext),
            escapeshellarg($webSocketImage),
        ), timeoutSeconds: 1800));

        if (! $build->successful()) {
            throw new RuntimeException("Could not build {$webSocketImage} on {$host->config->host}: {$build->output()}{$build->errorOutput()}");
        }

        $save = $timer->measure('gateway-artifacts.push.websocket-save', fn (): ProcessResult => $host->run(sprintf(
            <<<'BASH'
docker save %1$s -o %2$s
chmod 0644 %2$s
BASH,
            escapeshellarg($webSocketImage),
            escapeshellarg($archive),
        ), timeoutSeconds: 1800));

        if (! $save->successful()) {
            throw new RuntimeException("Could not save {$webSocketImage} on {$host->config->host}: {$save->output()}{$save->errorOutput()}");
        }

        $cacheWrite = $timer->measure(
            'gateway-artifacts.push.websocket-cache-write',
            fn (): ProcessResult => $host->run(sprintf(
                'mkdir -p %s && cp %s %s && chmod 0644 %s',
                escapeshellarg(dirname($cacheArchive)),
                escapeshellarg($archive),
                escapeshellarg($cacheArchive),
                escapeshellarg($cacheArchive),
            ), timeoutSeconds: 300),
        );

        if (! $cacheWrite->successful()) {
            throw new RuntimeException("Could not cache {$webSocketImage} on {$host->config->host}: {$cacheWrite->errorOutput()}");
        }

        $cleanup = $timer->measure(
            'gateway-artifacts.push.websocket-context-cleanup',
            fn (): ProcessResult => $host->run('rm -rf '.escapeshellarg($remoteBuildContext), timeoutSeconds: 30),
        );

        if (! $cleanup->successful()) {
            throw new RuntimeException("Could not clean remote websocket image build context on {$host->config->host}: {$cleanup->errorOutput()}");
        }
    }

    private function cleanupRemoteCliBinaryBundle(IncusHost $host, string $remoteDir): void
    {
        $host->run('rm -rf '.escapeshellarg($remoteDir).' || true', timeoutSeconds: 30);
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
                escapeshellarg(repo_path()),
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
            './apps/gateway/database/*.sqlite',
            './apps/gateway/database/*.sqlite-*',
            './apps/gateway/bootstrap/cache/*.php',
            './node_modules',
            './apps/gateway/node_modules',
            './apps/gateway/public/build',
            './apps/gateway/public/hot',
            './apps/gateway/public/storage',
            './apps/gateway/storage/app/orbit/*',
            './apps/gateway/storage/framework/cache/data/*',
            './apps/gateway/storage/framework/e2e/*',
            './apps/gateway/storage/framework/sessions/*',
            './apps/gateway/storage/framework/ssh-known-hosts/*',
            './apps/gateway/storage/framework/testing/*',
            './apps/gateway/storage/framework/views/*',
            './apps/gateway/storage/logs/*',
            './apps/gateway/storage/pail',
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
            escapeshellarg(repo_path()),
        );

        $result = Process::timeout(300)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException("Failed to build source archive: {$result->errorOutput()}");
        }

        return $archive;
    }

    private function stageBundleScript(string $bundleDir, string $name): void
    {
        $source = repo_path('bin/'.$name);

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

    /**
     * @return list<string>|null
     */
    private function selectedArtifactRoles(): ?array
    {
        $roles = $this->stringOption('roles');
        $allRoles = (bool) $this->option('all-roles');

        if ($roles !== null && $allRoles) {
            throw new InvalidArgumentException('Choose either --roles or --all-roles, not both.');
        }

        if ($roles !== null && E2ETopologyArtifactNamespace::artifactSet() === E2ETopologyArtifactNamespace::BaseArtifactSet) {
            throw new InvalidArgumentException('Set ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE when using --roles for Incus selected-role rebakes; omit --roles to rebuild the shared base artifact set.');
        }

        if (E2ETopologyArtifactNamespace::hasCustomArtifactSet() && $roles === null && ! $allRoles) {
            throw new InvalidArgumentException('Set --roles or --all-roles when ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE is set.');
        }

        if ($roles === null) {
            return null;
        }

        return E2EPreparedTopology::parseArtifactRoles($roles);
    }

    /**
     * @param  list<string>|null  $artifactRoles
     * @return list<string>
     */
    private function sourceRolesFor(E2ETopologyKind $buildKind, ?array $artifactRoles): array
    {
        $roles = IncusTopologyTemplate::rolesFor($buildKind);

        if ($artifactRoles === null) {
            return $roles;
        }

        return array_values(array_filter(
            $roles,
            fn (string $role): bool => in_array(E2EPreparedTopology::artifactRoleForIncusRole($role), $artifactRoles, true)
                || array_any($artifactRoles, fn ($artifactRole) => E2EPreparedTopology::incusRoleForArtifactRole($artifactRole) === $role),
        ));
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
