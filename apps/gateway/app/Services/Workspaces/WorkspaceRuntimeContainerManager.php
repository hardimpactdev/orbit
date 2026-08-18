<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Workspaces\WorkspaceRuntimeArtifactRemovalOutcome;
use App\Enums\Workspaces\WorkspaceRuntimeContainerApplyOutcome;
use App\Models\Node;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Apps\AppDevelopmentPackagesMount;
use App\Services\Ca\OrbitCaService;
use App\Services\Nodes\NodeHostPaths;
use App\Services\RemoteShell\Exceptions\RemoteShellProtocolException;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Tools\ToolScriptDispatcher;
use RuntimeException;
use Throwable;

final readonly class WorkspaceRuntimeContainerManager
{
    /**
     * @mago-expect lint:excessive-parameter-list
     */
    public function __construct(
        private DockerCommandBuilder $commands,
        private OrbitCaService $ca = new OrbitCaService,
        private AppDevelopmentInnerTlsPolicy $innerTlsPolicy = new AppDevelopmentInnerTlsPolicy,
        private NodeHostPaths $nodeHostPaths = new NodeHostPaths,
        private mixed $localExecutor = null,
        private ?ToolScriptDispatcher $scripts = null,
    ) {}

    /**
     * @mago-expect lint:halstead
     */
    public function apply(
        Node $node,
        WorkspaceRuntimeContainer $container,
        bool $restartIfRunning = false,
    ): WorkspaceRuntimeContainerApplyOutcome {
        if ($this->localExecutor($node) instanceof RemoteLocalExecutor) {
            return $this->applyThroughLocalExecutor($node, $container, $restartIfRunning);
        }

        $this->ensureNetwork($node, $container);

        // Container inspect runs before the image preflight so we know whether
        // a container already exists on the node. The image preflight then
        // uses that knowledge to throw with the correct hadExistingContainer
        // signal when the probe itself fails for an unknown reason.
        $inspection = $this->inspect($node, $container);
        $hadExistingContainer = $inspection !== null;

        // The selected FrankenPHP image must be present on the node for every
        // apply outcome — including the matching-running ("Unchanged") and
        // matching-stopped ("Started") paths. If the image was pruned out
        // from under a still-existing container, doctor/workspace surfaces
        // must report this as `workspace.php_version_unavailable` rather than
        // treating the runtime as healthy or collapsing it into runtime-
        // artifact drift.
        $this->ensureImageAvailable($node, $container, $hadExistingContainer);

        try {
            if ($inspection === null) {
                $this->createContainer($node, $container);

                return WorkspaceRuntimeContainerApplyOutcome::Created;
            }

            if (! $this->matchesSpec($inspection, $container)) {
                $this->runRequired(
                    $node,
                    $this->commands->containerRemove($container->name()),
                    "remove drifted {$container->name()} container",
                );
                $this->createContainer($node, $container);

                return WorkspaceRuntimeContainerApplyOutcome::Recreated;
            }

            if (! $this->isRunning($inspection)) {
                $this->runRequired(
                    $node,
                    $this->commands->containerStart($container->name()),
                    "start {$container->name()} container",
                );

                return WorkspaceRuntimeContainerApplyOutcome::Started;
            }

            // Default doctor/setup convergence leaves a matching running
            // container alone. Workspace env apply opts into a forced restart
            // so PHP reloads the newly published .env without recreating the
            // container when the runtime spec is otherwise unchanged.
            if (! $restartIfRunning) {
                return WorkspaceRuntimeContainerApplyOutcome::Unchanged;
            }

            $this->runRequired(
                $node,
                $this->commands->containerRestart($container->name()),
                "restart {$container->name()} container",
            );

            return WorkspaceRuntimeContainerApplyOutcome::Restarted;
        } catch (WorkspaceRuntimeImageUnavailableException|WorkspaceRuntimeContainerApplyException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new WorkspaceRuntimeContainerApplyException(
                hadExistingContainer: $hadExistingContainer,
                message: $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    private function applyThroughLocalExecutor(
        Node $node,
        WorkspaceRuntimeContainer $container,
        bool $restartIfRunning = false,
    ): WorkspaceRuntimeContainerApplyOutcome {
        $result = $this->runRuntimeAction(
            node: $node,
            action: 'container:apply',
            payload: [
                'spec' => $this->runtimeContainerSpec($container),
                'runtime_config' => $this->runtimeConfigPayload($container),
                'restart_if_running' => $restartIfRunning,
            ],
            operation: 'workspace-runtime-container-apply',
        );

        if ($result->successful()) {
            try {
                $data = $this->runtimeSuccessData($result);
            } catch (RemoteShellProtocolException $exception) {
                throw new WorkspaceRuntimeContainerApplyException(
                    hadExistingContainer: false,
                    message: "Workspace runtime container apply returned an invalid success envelope: {$exception->getMessage()}",
                    previous: $exception,
                );
            }

            $outcome = $data['outcome'] ?? null;

            if (is_string($outcome)) {
                return WorkspaceRuntimeContainerApplyOutcome::from($outcome);
            }

            throw new WorkspaceRuntimeContainerApplyException(
                hadExistingContainer: false,
                message: 'Workspace runtime container apply response is missing an outcome.',
            );
        }

        $failure = $this->runtimeFailure($result);
        $code = $failure['code'];
        $meta = $failure['meta'];

        if ($code === 'app_runtime.image_unavailable') {
            throw new WorkspaceRuntimeImageUnavailableException(
                image: $this->stringMeta($meta, 'image') ?? $container->image(),
                phpVersion: $this->stringMeta($meta, 'php_version') ?? $this->phpVersionFromImage($container->image()),
                message: $failure['message'],
            );
        }

        throw new WorkspaceRuntimeContainerApplyException(
            hadExistingContainer: $this->boolMeta($meta, 'had_existing_container'),
            message: $failure['message'],
        );
    }

    private function removeThroughLocalExecutor(
        Node $node,
        string $containerName,
    ): WorkspaceRuntimeArtifactRemovalOutcome {
        $result = $this->runRuntimeAction(
            node: $node,
            action: 'container:remove',
            payload: [
                'container' => $containerName,
            ],
            operation: 'workspace-runtime-container-remove',
        );

        if (! $result->successful()) {
            return WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining;
        }

        try {
            $changed = $this->runtimeSuccessData($result)['changed'] ?? false;
        } catch (RemoteShellProtocolException) {
            return WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining;
        }

        return $changed === true
            ? WorkspaceRuntimeArtifactRemovalOutcome::Removed
            : WorkspaceRuntimeArtifactRemovalOutcome::AlreadyAbsent;
    }

    private function removeRuntimeConfigFileThroughLocalExecutor(
        Node $node,
        string $path,
    ): WorkspaceRuntimeArtifactRemovalOutcome {
        $result = $this->runRuntimeAction(
            node: $node,
            action: 'runtime-config:remove',
            payload: [
                'path' => $path,
            ],
            operation: 'workspace-runtime-config-remove',
        );

        if (! $result->successful()) {
            return WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining;
        }

        try {
            $changed = $this->runtimeSuccessData($result)['changed'] ?? false;
        } catch (RemoteShellProtocolException) {
            return WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining;
        }

        return $changed === true
            ? WorkspaceRuntimeArtifactRemovalOutcome::Removed
            : WorkspaceRuntimeArtifactRemovalOutcome::AlreadyAbsent;
    }

    private function writeRuntimeConfigFileThroughLocalExecutor(
        Node $node,
        WorkspaceRuntimeContainer $container,
    ): void {
        $result = $this->runRuntimeAction(
            node: $node,
            action: 'runtime-config:write',
            payload: [
                'runtime_config' => $this->runtimeConfigPayload($container),
            ],
            operation: 'workspace-runtime-config-write',
        );

        if ($result->successful()) {
            return;
        }

        $failure = $this->runtimeFailure($result);

        throw new RuntimeException(
            "Failed to write managed runtime config for {$container->appSlug()}/{$container->workspaceSlug()} on {$node->name}: {$failure['message']}",
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function runRuntimeAction(Node $node, string $action, array $payload, string $operation): RemoteShellResult
    {
        $localExecutor = $this->localExecutor($node);

        if (! $localExecutor instanceof RemoteLocalExecutor) {
            throw new RuntimeException('Workspace runtime local executor is unavailable.');
        }

        try {
            return $localExecutor->runInternal(
                node: $node,
                commandName: 'internal:app-runtime-container',
                arguments: [$action],
                transportOptions: [
                    'throw' => false,
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => $operation,
                    ],
                    'input' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'strict' => false,
                    'timeout' => 120,
                ],
            );
        } catch (Throwable $exception) {
            return new RemoteShellResult(
                exitCode: 1,
                stdout: '',
                stderr: $exception->getMessage(),
                durationMs: 0,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeContainerSpec(WorkspaceRuntimeContainer $container): array
    {
        return [
            ...$container->spec(),
            'kind' => 'workspace',
            'runtime_user' => null,
            'docker_user' => null,
            'expected_hash' => $container->specHash(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeConfigPayload(WorkspaceRuntimeContainer $container): array
    {
        $phpIniHostPath = $this->findPhpIniMountSource($container);

        return [
            'path' => $phpIniHostPath,
            'content_base64' => base64_encode($container->phpIniContent()),
            'directories' => [
                [
                    'path' => dirname($phpIniHostPath),
                    'mode' => '0755',
                    'owner' => null,
                    'group' => null,
                ],
                ...$this->runtimeMountDirectories($container),
            ],
            'trust_pool' => $this->runtimeTrustPoolPayload($container),
        ];
    }

    /**
     * @return list<array{path: string, mode: string, owner: string|null, group: string|null}>
     */
    private function runtimeMountDirectories(WorkspaceRuntimeContainer $container): array
    {
        $directories = [];

        foreach ($this->packagesMountDirectories($container) as $path => $user) {
            $directories[$path] = [
                'path' => $path,
                'mode' => '0775',
                'owner' => $user,
                'group' => $user,
            ];
        }

        foreach ($this->configuredMountDirectories($container) as $path => $user) {
            $directories[$path] = [
                'path' => $path,
                'mode' => '0775',
                'owner' => $user,
                'group' => $user,
            ];
        }

        return array_values($directories);
    }

    /**
     * @return array<string, string>
     */
    private function packagesMountDirectories(WorkspaceRuntimeContainer $container): array
    {
        $sources = [];

        foreach ($container->mounts() as $mount) {
            if ($mount['target'] !== AppDevelopmentPackagesMount::Target) {
                continue;
            }

            $sourceUser = AppDevelopmentPackagesMount::userForSafeSource($mount['source']);

            if ($sourceUser === null) {
                throw new RuntimeException(
                    "Workspace runtime container {$container->name()} has an unsafe packages mount source.",
                );
            }

            $sources[$mount['source']] = $sourceUser;
        }

        return $sources;
    }

    /**
     * @return array<string, string>
     */
    private function configuredMountDirectories(WorkspaceRuntimeContainer $container): array
    {
        $builtInSources = $this->builtInMountSources($container);
        $sources = [];

        foreach ($container->mounts() as $mount) {
            if ($this->isBuiltInRuntimeMountTarget($mount['target'])) {
                continue;
            }

            if (in_array($mount['source'], $builtInSources, true)) {
                continue;
            }

            $sourceUser = $this->userForSafeConfiguredMountSource($mount['source']);

            if ($sourceUser === null) {
                throw new RuntimeException(
                    "Workspace runtime container {$container->name()} has an unsafe configured runtime mount source.",
                );
            }

            $sources[$mount['source']] = $sourceUser;
        }

        return $sources;
    }

    /**
     * @return array{path: string, content_base64: string}|null
     */
    private function runtimeTrustPoolPayload(WorkspaceRuntimeContainer $container): ?array
    {
        $hostPath = $this->runtimeTrustPoolHostPath($container);

        if ($hostPath === null) {
            return null;
        }

        return [
            'path' => $hostPath,
            'content_base64' => base64_encode($this->ca->rootCert()),
        ];
    }

    /**
     * @return array{code: string, message: string, meta: array<string, mixed>}
     */
    private function runtimeFailure(RemoteShellResult $result): array
    {
        $error = RemoteShellSuccessData::errorFromJsonEnvelope($result);
        $code = $error['code'] ?? null;
        $message = $error['message'] ?? null;
        $meta = $this->stringKeyedArray($error['meta'] ?? []);

        if (! is_string($message) || trim($message) === '') {
            $output = trim($result->errorOutput().' '.$result->stdout);
            $message = $output !== '' ? $output : 'unknown error';
        }

        return [
            'code' => is_string($code) ? $code : 'workspace_runtime.failed',
            'message' => $message,
            'meta' => $meta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value) || ! array_all(array_keys($value), static fn ($key) => is_string($key))) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeSuccessData(RemoteShellResult $result): array
    {
        return RemoteShellSuccessData::fromJsonEnvelopeOrFail($result);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function stringMeta(array $meta, string $key): ?string
    {
        $value = $meta[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function boolMeta(array $meta, string $key): bool
    {
        $value = $meta[$key] ?? false;

        return is_bool($value) ? $value : false;
    }

    private function ensureImageAvailable(
        Node $node,
        WorkspaceRuntimeContainer $container,
        bool $hadExistingContainer,
    ): void {
        $image = $container->image();
        $result = $this->run($node, 'docker image inspect '.escapeshellarg($image));

        if ($result->successful()) {
            return;
        }

        if ($this->isDockerNoSuchImage($result)) {
            throw new WorkspaceRuntimeImageUnavailableException(
                image: $image,
                phpVersion: $this->phpVersionFromImage($image),
                message: "FrankenPHP runtime image '{$image}' is not available on node '{$node->name}'.",
            );
        }

        $detail = trim($result->errorOutput().' '.$result->stdout);
        $detail = $detail !== '' ? $detail : 'unknown docker image inspect failure';

        throw new WorkspaceRuntimeContainerApplyException(
            hadExistingContainer: $hadExistingContainer,
            message: "Failed to verify FrankenPHP runtime image '{$image}' on node '{$node->name}': {$detail}",
        );
    }

    private function isDockerNoSuchImage(RemoteShellResult $result): bool
    {
        $message = $result->stderr.' '.$result->stdout;

        return preg_match('/No such image/i', $message) === 1;
    }

    private function phpVersionFromImage(string $image): string
    {
        if (preg_match('/php(?<version>\d+\.\d+)/', $image, $matches) === 1) {
            return $matches['version'];
        }

        return '';
    }

    /**
     * Tri-state container removal. Returns:
     * - AlreadyAbsent only when Docker confirms the container does not exist
     *   on the node;
     * - Removed when the container existed and was removed;
     * - FailedRemaining when the container existed but could not be removed,
     *   or when the existence probe failed for an unknown reason. Unknown
     *   probe failures must NOT be reported as clean absence because the
     *   artifact may still be on the node.
     */
    public function remove(Node $node, string $appSlug, string $workspaceSlug): WorkspaceRuntimeArtifactRemovalOutcome
    {
        if ($this->localExecutor($node) instanceof RemoteLocalExecutor) {
            return $this->removeThroughLocalExecutor($node, $this->containerName($appSlug, $workspaceSlug));
        }

        $name = $this->containerName($appSlug, $workspaceSlug);

        $inspect = $this->run($node, $this->commands->containerInspect($name));

        if ($inspect->successful() && trim($inspect->stdout) !== '') {
            $result = $this->run($node, $this->commands->containerRemove($name));

            return $result->successful()
                ? WorkspaceRuntimeArtifactRemovalOutcome::Removed
                : WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining;
        }

        if ($this->isDockerNoSuchObject($inspect)) {
            return WorkspaceRuntimeArtifactRemovalOutcome::AlreadyAbsent;
        }

        return WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining;
    }

    /**
     * Tri-state managed runtime config file removal. The php.ini snippet
     * mounted into the FrankenPHP workspace container lives at
     * `~/.config/orbit/workspaces/<app>-<workspace>.ini` on the node.
     */
    public function removeRuntimeConfigFile(
        Node $node,
        string $appSlug,
        string $workspaceSlug,
    ): WorkspaceRuntimeArtifactRemovalOutcome {
        if ($this->localExecutor($node) instanceof RemoteLocalExecutor) {
            return $this->removeRuntimeConfigFileThroughLocalExecutor(
                $node,
                $this->runtimeConfigPath($node, $appSlug, $workspaceSlug),
            );
        }

        $path = $this->runtimeConfigPath($node, $appSlug, $workspaceSlug);

        $existence = $this->probeRuntimeConfigExistence($node, $path);

        if ($existence === 'absent') {
            return WorkspaceRuntimeArtifactRemovalOutcome::AlreadyAbsent;
        }

        if ($existence !== 'present') {
            return WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining;
        }

        $remove = $this->run($node, 'rm -f '.escapeshellarg($path));

        if (! $remove->successful()) {
            return WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining;
        }

        return $this->probeRuntimeConfigExistence($node, $path) === 'absent'
            ? WorkspaceRuntimeArtifactRemovalOutcome::Removed
            : WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining;
    }

    private function probeRuntimeConfigExistence(Node $node, string $path): string
    {
        $script = sprintf(
            <<<'SH'
                if [ -e %1$s ]; then
                    printf 'orbit-container-config-probe:present\n'
                elif [ ! -e %1$s ]; then
                    printf 'orbit-container-config-probe:absent\n'
                else
                    printf 'orbit-container-config-probe:error\n'
                fi
                SH,
            escapeshellarg($path),
        );

        $result = $this->run($node, $script);

        if (! $result->successful()) {
            return 'error';
        }

        if (str_contains($result->stdout, 'orbit-container-config-probe:present')) {
            return 'present';
        }

        if (str_contains($result->stdout, 'orbit-container-config-probe:absent')) {
            return 'absent';
        }

        return 'error';
    }

    private function isDockerNoSuchObject(RemoteShellResult $result): bool
    {
        $message = $result->stderr.' '.$result->stdout;

        return preg_match('/No such (object|container)/i', $message) === 1;
    }

    public function writeRuntimeConfigFile(Node $node, WorkspaceRuntimeContainer $container): void
    {
        if ($this->localExecutor($node) instanceof RemoteLocalExecutor) {
            $this->writeRuntimeConfigFileThroughLocalExecutor($node, $container);

            return;
        }

        $this->runRequired(
            $node,
            $this->renderRuntimeConfigWriteScript($container),
            "write managed runtime config for {$container->appSlug()}/{$container->workspaceSlug()}",
        );
    }

    public function runtimeConfigPath(Node $node, string $appSlug, string $workspaceSlug): string
    {
        return $this->nodeHostPaths->workspaceRuntimeConfigPath($node, $appSlug, $workspaceSlug);
    }

    public function containerName(string $appSlug, string $workspaceSlug): string
    {
        return "orbit-ws-{$appSlug}-{$workspaceSlug}";
    }

    private function ensureNetwork(Node $node, WorkspaceRuntimeContainer $container): void
    {
        $result = $this->run($node, $this->commands->networkInspect($container->network()));

        if ($result->successful()) {
            return;
        }

        $this->runRequired(
            $node,
            $this->commands->networkCreate($container->network()),
            "create {$container->network()} Docker network",
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inspect(Node $node, WorkspaceRuntimeContainer $container): ?array
    {
        $result = $this->run($node, $this->commands->containerInspect($container->name()));

        if (! $result->successful()) {
            return null;
        }

        $output = trim($result->stdout);

        if ($output === '') {
            return null;
        }

        $inspection = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($inspection)) {
            throw new RuntimeException(
                "Docker returned an invalid inspect payload for {$container->name()} on {$node->name}.",
            );
        }

        return $inspection;
    }

    private function createContainer(Node $node, WorkspaceRuntimeContainer $container): void
    {
        $this->runRequired(
            $node,
            $this->renderInstallAndRunScript($container),
            "create {$container->name()} container",
        );
    }

    private function renderInstallAndRunScript(WorkspaceRuntimeContainer $container): string
    {
        return $this->renderRuntimeConfigWriteScript($container).PHP_EOL.$this->commands->runDetached($container);
    }

    private function renderRuntimeConfigWriteScript(WorkspaceRuntimeContainer $container): string
    {
        $phpIniHostPath = $this->findPhpIniMountSource($container);
        $phpIniDirectory = dirname($phpIniHostPath);
        $phpIniContent = $container->phpIniContent();
        $trustPoolInstallScript = $this->renderRuntimeTrustPoolInstallScript($container);
        $packagesMountScript = $this->renderPackagesMountDirectoryScript($container);
        $configuredMountScript = $this->renderConfiguredMountDirectoryScript($container);

        return sprintf(
            <<<'SH'
                set -e
                %s
                install -d -m 0755 %s
                printf %%s %s | base64 -d > %s
                chmod 0644 %s
                %s
                %s
                SH,
            $trustPoolInstallScript,
            escapeshellarg($phpIniDirectory),
            escapeshellarg(base64_encode($phpIniContent)),
            escapeshellarg($phpIniHostPath),
            escapeshellarg($phpIniHostPath),
            $packagesMountScript,
            $configuredMountScript,
        );
    }

    private function renderRuntimeTrustPoolInstallScript(WorkspaceRuntimeContainer $container): string
    {
        $hostPath = $this->runtimeTrustPoolHostPath($container);

        if ($hostPath === null) {
            return '';
        }

        return (
            $this->innerTlsPolicy->trustPoolInstallScript(
                $hostPath,
                $this->ca->rootCert(),
            )."\n"
        );
    }

    private function runtimeTrustPoolHostPath(WorkspaceRuntimeContainer $container): ?string
    {
        foreach ($container->mounts() as $mount) {
            if ($mount['target'] === AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath) {
                return $mount['source'];
            }
        }

        return null;
    }

    private function renderPackagesMountDirectoryScript(WorkspaceRuntimeContainer $container): string
    {
        $sources = [];

        foreach ($container->mounts() as $mount) {
            if ($mount['target'] !== AppDevelopmentPackagesMount::Target) {
                continue;
            }

            $sourceUser = AppDevelopmentPackagesMount::userForSafeSource($mount['source']);

            if ($sourceUser === null) {
                throw new RuntimeException(
                    "Workspace runtime container {$container->name()} has an unsafe packages mount source.",
                );
            }

            $sources[$mount['source']] = $sourceUser;
        }

        if ($sources === []) {
            return '';
        }

        $lines = [];

        foreach ($sources as $source => $sourceUser) {
            $lines[] = sprintf(
                'sudo install -d -m 0775 -o %s -g %s %s',
                escapeshellarg($sourceUser),
                escapeshellarg($sourceUser),
                escapeshellarg($source),
            );
        }

        return implode("\n", $lines);
    }

    private function renderConfiguredMountDirectoryScript(WorkspaceRuntimeContainer $container): string
    {
        $builtInSources = $this->builtInMountSources($container);
        $sources = [];

        foreach ($container->mounts() as $mount) {
            if ($this->isBuiltInRuntimeMountTarget($mount['target'])) {
                continue;
            }

            if (in_array($mount['source'], $builtInSources, true)) {
                continue;
            }

            $sourceUser = $this->userForSafeConfiguredMountSource($mount['source']);

            if ($sourceUser === null) {
                throw new RuntimeException(
                    "Workspace runtime container {$container->name()} has an unsafe configured runtime mount source.",
                );
            }

            $sources[$mount['source']] = $sourceUser;
        }

        if ($sources === []) {
            return '';
        }

        $lines = [];

        foreach ($sources as $source => $sourceUser) {
            $lines[] = sprintf(
                'sudo install -d -m 0775 -o %s -g %s %s',
                escapeshellarg($sourceUser),
                escapeshellarg($sourceUser),
                escapeshellarg($source),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function builtInMountSources(WorkspaceRuntimeContainer $container): array
    {
        $sources = [];

        foreach ($container->mounts() as $mount) {
            if ($this->isBuiltInRuntimeMountTarget($mount['target'])) {
                $sources[] = $mount['source'];
            }
        }

        return array_values(array_unique($sources));
    }

    private function isBuiltInRuntimeMountTarget(string $target): bool
    {
        return in_array(
            $target,
            [
                WorkspaceRuntimeContainer::SourceTarget,
                WorkspaceRuntimeContainer::PhpIniMountTarget,
                AppDevelopmentPackagesMount::Target,
                AppDevelopmentInnerTlsPolicy::RuntimeTlsCertContainerPath,
                AppDevelopmentInnerTlsPolicy::RuntimeTlsKeyContainerPath,
                AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath,
            ],
            true,
        );
    }

    private function userForSafeConfiguredMountSource(string $source): ?string
    {
        $match = NodeHostPaths::safeUserPath($source);

        if ($match === null) {
            return null;
        }

        $path = $match['path'];

        foreach (['.aws', '.config', '.gnupg', '.ssh'] as $sensitiveDirectory) {
            if ($path === $sensitiveDirectory || str_starts_with($path, "{$sensitiveDirectory}/")) {
                return null;
            }
        }

        if (in_array(needle: $path, haystack: ['.netrc', '.npmrc', '.composer/auth.json'], strict: true)) {
            return null;
        }

        if (str_starts_with($path, '.composer/auth.json/')) {
            return null;
        }

        return $match['user'];
    }

    private function findPhpIniMountSource(WorkspaceRuntimeContainer $container): string
    {
        foreach ($container->mounts() as $mount) {
            if ($mount['target'] === WorkspaceRuntimeContainer::PhpIniMountTarget) {
                return $mount['source'];
            }
        }

        throw new RuntimeException("Workspace runtime container {$container->name()} is missing its php.ini mount.");
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    private function matchesSpec(array $inspection, WorkspaceRuntimeContainer $container): bool
    {
        $labels = $inspection['Config']['Labels'] ?? [];

        if (! is_array($labels)) {
            return false;
        }

        return ($labels[WorkspaceRuntimeContainer::SpecHashLabel] ?? null) === $container->specHash();
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    private function isRunning(array $inspection): bool
    {
        return ($inspection['State']['Running'] ?? false) === true;
    }

    private function run(Node $node, string $script): RemoteShellResult
    {
        return ($this->scripts ?? app(ToolScriptDispatcher::class))->run(
            $node,
            'orbit-workspace-runtime',
            'reconfigure',
            $script,
        );
    }

    private function runRequired(Node $node, string $script, string $step): void
    {
        $result = $this->run($node, $script);

        if ($result->successful()) {
            return;
        }

        $output = trim($result->errorOutput().' '.$result->stdout);
        $message = $output !== '' ? $output : 'unknown error';

        throw new RuntimeException("Failed to {$step} on {$node->name}: {$message}");
    }

    private function localExecutor(Node $node): ?RemoteLocalExecutor
    {
        if (! $this->localExecutor instanceof RemoteLocalExecutor) {
            return null;
        }

        return $this->localExecutor;
    }
}
