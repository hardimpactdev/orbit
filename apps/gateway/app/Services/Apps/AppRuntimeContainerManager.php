<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Contracts\ConvergesAppRuntimeContainers;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeArtifactRemovalOutcome;
use App\Enums\Apps\AppRuntimeContainerApplyOutcome;
use App\Models\Node;
use App\Services\Ca\OrbitCaService;
use App\Services\Nodes\NodeHostPaths;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Tools\ToolScriptDispatcher;
use JsonException;
use RuntimeException;
use Throwable;

final readonly class AppRuntimeContainerManager implements ConvergesAppRuntimeContainers
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

    public function apply(Node $node, AppRuntimeContainer $container): AppRuntimeContainerApplyOutcome
    {
        if ($this->localExecutor($node) instanceof RemoteLocalExecutor) {
            return $this->applyThroughLocalExecutor($node, $container);
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
        // from under a still-existing container, doctor/app surfaces must
        // report this as `app.php_version_unavailable` rather than treating
        // the runtime as healthy or collapsing it into runtime-artifact drift.
        //
        // Unknown Docker daemon/permission failures during the image probe
        // are NOT misreported as missing-image; they throw a generic
        // AppRuntimeContainerApplyException so callers surface a
        // runtime_container_* drift instead of `app.php_version_unavailable`.
        $this->ensureImageAvailable($node, $container, $hadExistingContainer);

        try {
            if ($inspection === null) {
                $container = $this->withResolvedRuntimeUser($node, $container, hadExistingContainer: false);
                $this->createContainer($node, $container);

                return AppRuntimeContainerApplyOutcome::Created;
            }

            if (! $this->matchesSpec($inspection, $container)) {
                $container = $this->withResolvedRuntimeUser($node, $container, hadExistingContainer: true);
                $this->runRequired(
                    $node,
                    $this->commands->containerRemove($container->name()),
                    "remove drifted {$container->name()} container",
                );
                $this->createContainer($node, $container);

                return AppRuntimeContainerApplyOutcome::Recreated;
            }

            if (! $this->isRunning($inspection)) {
                $this->runRequired(
                    $node,
                    $this->commands->containerStart($container->name()),
                    "start {$container->name()} container",
                );

                return AppRuntimeContainerApplyOutcome::Started;
            }

            return AppRuntimeContainerApplyOutcome::Unchanged;
        } catch (
            AppRuntimeImageUnavailableException|AppRuntimeContainerApplyException|AppRuntimeUserUnavailableException $exception
        ) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AppRuntimeContainerApplyException(
                hadExistingContainer: $hadExistingContainer,
                message: $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    private function applyThroughLocalExecutor(
        Node $node,
        AppRuntimeContainer $container,
    ): AppRuntimeContainerApplyOutcome {
        $result = $this->runRuntimeAction(
            node: $node,
            action: 'container:apply',
            payload: [
                'spec' => $this->runtimeContainerSpec($container),
                'runtime_config' => $this->runtimeConfigPayload($container),
            ],
            operation: 'app-runtime-container-apply',
        );

        if ($result->successful()) {
            $data = $this->runtimeSuccessData($result);
            $outcome = $data['outcome'] ?? null;

            if (is_string($outcome)) {
                return AppRuntimeContainerApplyOutcome::from($outcome);
            }

            throw new AppRuntimeContainerApplyException(
                hadExistingContainer: false,
                message: 'App runtime container apply response is missing an outcome.',
            );
        }

        $failure = $this->runtimeFailure($result);
        $code = $failure['code'];
        $meta = $failure['meta'];

        if ($code === 'app_runtime.image_unavailable') {
            throw new AppRuntimeImageUnavailableException(
                image: $this->stringMeta($meta, 'image') ?? $container->image(),
                phpVersion: $this->stringMeta($meta, 'php_version') ?? $this->phpVersionFromImage($container->image()),
                message: $failure['message'],
            );
        }

        if ($code === 'app_runtime.user_unavailable') {
            throw new AppRuntimeUserUnavailableException(
                runtimeUser: $container->runtimeUser() ?? '',
                message: $failure['message'],
            );
        }

        throw new AppRuntimeContainerApplyException(
            hadExistingContainer: $this->boolMeta($meta, 'had_existing_container'),
            message: $failure['message'],
        );
    }

    private function removeThroughLocalExecutor(Node $node, string $containerName): AppRuntimeArtifactRemovalOutcome
    {
        $result = $this->runRuntimeAction(
            node: $node,
            action: 'container:remove',
            payload: [
                'container' => $containerName,
            ],
            operation: 'app-runtime-container-remove',
        );

        if (! $result->successful()) {
            return AppRuntimeArtifactRemovalOutcome::FailedRemaining;
        }

        $changed = $this->runtimeSuccessData($result)['changed'] ?? false;

        return $changed === true
            ? AppRuntimeArtifactRemovalOutcome::Removed
            : AppRuntimeArtifactRemovalOutcome::AlreadyAbsent;
    }

    private function removeRuntimeConfigFileThroughLocalExecutor(
        Node $node,
        string $path,
    ): AppRuntimeArtifactRemovalOutcome {
        $result = $this->runRuntimeAction(
            node: $node,
            action: 'runtime-config:remove',
            payload: [
                'path' => $path,
            ],
            operation: 'app-runtime-config-remove',
        );

        if (! $result->successful()) {
            return AppRuntimeArtifactRemovalOutcome::FailedRemaining;
        }

        $changed = $this->runtimeSuccessData($result)['changed'] ?? false;

        return $changed === true
            ? AppRuntimeArtifactRemovalOutcome::Removed
            : AppRuntimeArtifactRemovalOutcome::AlreadyAbsent;
    }

    private function writeRuntimeConfigFileThroughLocalExecutor(Node $node, AppRuntimeContainer $container): void
    {
        $result = $this->runRuntimeAction(
            node: $node,
            action: 'runtime-config:write',
            payload: [
                'runtime_config' => $this->runtimeConfigPayload($container),
            ],
            operation: 'app-runtime-config-write',
        );

        if ($result->successful()) {
            return;
        }

        $failure = $this->runtimeFailure($result);

        throw new RuntimeException(
            "Failed to write managed runtime config for {$container->appSlug()} on {$node->name}: {$failure['message']}",
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function runRuntimeAction(Node $node, string $action, array $payload, string $operation): RemoteShellResult
    {
        $localExecutor = $this->localExecutor($node);

        if (! $localExecutor instanceof RemoteLocalExecutor) {
            throw new RuntimeException('App runtime local executor is unavailable.');
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
    private function runtimeContainerSpec(AppRuntimeContainer $container): array
    {
        return [
            ...$container->spec(),
            'kind' => 'app',
            'workspace_slug' => null,
            'docker_user' => $container->dockerUser(),
            'expected_hash' => $container->specHash(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeConfigPayload(AppRuntimeContainer $container): array
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
    private function runtimeMountDirectories(AppRuntimeContainer $container): array
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
    private function packagesMountDirectories(AppRuntimeContainer $container): array
    {
        $sources = [];

        foreach ($container->mounts() as $mount) {
            if ($mount['target'] !== AppDevelopmentPackagesMount::Target) {
                continue;
            }

            $sourceUser = AppDevelopmentPackagesMount::userForSafeSource($mount['source']);

            if ($sourceUser === null) {
                throw new RuntimeException(
                    "App runtime container {$container->name()} has an unsafe packages mount source.",
                );
            }

            $sources[$mount['source']] = $sourceUser;
        }

        return $sources;
    }

    /**
     * @return array<string, string>
     */
    private function configuredMountDirectories(AppRuntimeContainer $container): array
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
                    "App runtime container {$container->name()} has an unsafe configured runtime mount source.",
                );
            }

            $sources[$mount['source']] = $sourceUser;
        }

        return $sources;
    }

    /**
     * @return array{path: string, content_base64: string}|null
     */
    private function runtimeTrustPoolPayload(AppRuntimeContainer $container): ?array
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
            'code' => is_string($code) ? $code : 'app_runtime.failed',
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
        $data = RemoteShellSuccessData::fromJsonEnvelope($result);

        if ($data !== []) {
            return $data;
        }

        try {
            /** @var mixed $payload */
            $payload = json_decode(trim($result->stdout), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($payload) || ! array_all(array_keys($payload), static fn ($key) => is_string($key))) {
            return [];
        }

        /** @var array<string, mixed> $payload */
        return $payload;
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

    /**
     * Preflight the selected FrankenPHP image on the node before
     * creating/recreating/starting/returning the container. Distinguishes
     * definite "image not on node" from unknown probe failure:
     *
     * - `docker image inspect` exits 0 and emits the image JSON → image present.
     * - Exits non-zero with stderr containing "No such image" → throw
     *   {@see AppRuntimeImageUnavailableException}, mapped to
     *   `app.php_version_unavailable` by callers.
     * - Other failures (daemon down, permission denied, SSH error) throw
     *   {@see AppRuntimeContainerApplyException}; this surfaces a
     *   runtime_container_missing/mismatch drift instead of misreporting the
     *   PHP version as unavailable. Unknown failures must NEVER reach the
     *   Unchanged/Started/Recreated/Created branches because that would
     *   imply the runtime image was verified when it was not.
     */
    private function ensureImageAvailable(Node $node, AppRuntimeContainer $container, bool $hadExistingContainer): void
    {
        $image = $container->image();
        $result = $this->run($node, 'docker image inspect '.escapeshellarg($image));

        // Exit 0 → docker reports the image present (regardless of whether
        // stdout carries the inspect JSON). Treat the success exit code as
        // authoritative; a downstream docker run will still fail loudly if
        // anything is wrong.
        if ($result->successful()) {
            return;
        }

        if ($this->isDockerNoSuchImage($result)) {
            throw new AppRuntimeImageUnavailableException(
                image: $image,
                phpVersion: $this->phpVersionFromImage($image),
                message: "FrankenPHP runtime image '{$image}' is not available on node '{$node->name}'.",
            );
        }

        $detail = trim($result->errorOutput().' '.$result->stdout);
        $detail = $detail !== '' ? $detail : 'unknown docker image inspect failure';

        throw new AppRuntimeContainerApplyException(
            hadExistingContainer: $hadExistingContainer,
            message: "Failed to verify FrankenPHP runtime image '{$image}' on node '{$node->name}': {$detail}",
        );
    }

    private function withResolvedRuntimeUser(
        Node $node,
        AppRuntimeContainer $container,
        bool $hadExistingContainer,
    ): AppRuntimeContainer {
        $runtimeUser = $container->runtimeUser();

        if ($runtimeUser === null) {
            return $container;
        }

        $result = $this->run($node, sprintf(
            "id -u %s\nid -g %s",
            escapeshellarg($runtimeUser),
            escapeshellarg($runtimeUser),
        ));

        if (! $result->successful()) {
            $message = trim($result->errorOutput().' '.$result->stdout);
            $message = $message !== '' ? $message : "Runtime user '{$runtimeUser}' is unavailable.";

            throw new AppRuntimeUserUnavailableException(
                runtimeUser: $runtimeUser,
                message: $message,
            );
        }

        $lines = array_values(array_filter(
            array_map(trim(...), explode("\n", $result->stdout)),
            static fn (string $line): bool => $line !== '',
        ));

        $uid = $lines[0] ?? '';
        $gid = $lines[1] ?? '';

        if (preg_match('/^\d+$/', $uid) !== 1 || preg_match('/^\d+$/', $gid) !== 1) {
            throw new AppRuntimeContainerApplyException(
                hadExistingContainer: $hadExistingContainer,
                message: "Failed to resolve runtime user '{$runtimeUser}' to numeric UID:GID on node '{$node->name}'.",
            );
        }

        return $container->withDockerUser("{$uid}:{$gid}");
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
     *   on the node (inspect emitted "No such object/container");
     * - Removed when the container existed and was removed;
     * - FailedRemaining when the container existed but could not be removed,
     *   or when the existence probe failed for an unknown reason (Docker
     *   daemon unavailable, permission denied, SSH/remote error). Unknown
     *   probe failures must NOT be reported as clean absence because the
     *   artifact may still be on the node.
     */
    public function remove(Node $node, string $appSlug): AppRuntimeArtifactRemovalOutcome
    {
        if ($this->localExecutor($node) instanceof RemoteLocalExecutor) {
            return $this->removeThroughLocalExecutor($node, "orbit-app-{$appSlug}");
        }

        $name = "orbit-app-{$appSlug}";

        $inspect = $this->run($node, $this->commands->containerInspect($name));

        if ($inspect->successful() && trim($inspect->stdout) !== '') {
            $result = $this->run($node, $this->commands->containerRemove($name));

            return $result->successful()
                ? AppRuntimeArtifactRemovalOutcome::Removed
                : AppRuntimeArtifactRemovalOutcome::FailedRemaining;
        }

        if ($this->isDockerNoSuchObject($inspect)) {
            return AppRuntimeArtifactRemovalOutcome::AlreadyAbsent;
        }

        return AppRuntimeArtifactRemovalOutcome::FailedRemaining;
    }

    /**
     * Tri-state managed runtime config file removal. The php.ini snippet
     * mounted into the FrankenPHP container lives at
     * `~/.config/orbit/apps/<slug>.ini` on the node. AlreadyAbsent is only
     * returned when the probe proves the file is missing; SSH/probe errors
     * are reported as FailedRemaining.
     */
    public function removeRuntimeConfigFile(Node $node, string $appSlug): AppRuntimeArtifactRemovalOutcome
    {
        if ($this->localExecutor($node) instanceof RemoteLocalExecutor) {
            return $this->removeRuntimeConfigFileThroughLocalExecutor(
                $node,
                $this->runtimeConfigPath($node, $appSlug),
            );
        }

        $path = $this->runtimeConfigPath($node, $appSlug);

        $existence = $this->probeRuntimeConfigExistence($node, $path);

        if ($existence === 'absent') {
            return AppRuntimeArtifactRemovalOutcome::AlreadyAbsent;
        }

        if ($existence !== 'present') {
            return AppRuntimeArtifactRemovalOutcome::FailedRemaining;
        }

        $remove = $this->run($node, 'rm -f '.escapeshellarg($path));

        if (! $remove->successful()) {
            return AppRuntimeArtifactRemovalOutcome::FailedRemaining;
        }

        return $this->probeRuntimeConfigExistence($node, $path) === 'absent'
            ? AppRuntimeArtifactRemovalOutcome::Removed
            : AppRuntimeArtifactRemovalOutcome::FailedRemaining;
    }

    /**
     * Probe a remote path for existence and distinguish proven absence from
     * SSH / remote shell errors. Returns one of `present`, `absent`, or
     * `error` (unknown state).
     */
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

    /**
     * Write or rewrite the managed runtime config file for an app to match
     * the rendered container's expected php.ini snippet. Used by doctor's
     * restore mode for app.runtime_config_missing / app.runtime_config_mismatch.
     */
    public function writeRuntimeConfigFile(Node $node, AppRuntimeContainer $container): void
    {
        if ($this->localExecutor($node) instanceof RemoteLocalExecutor) {
            $this->writeRuntimeConfigFileThroughLocalExecutor($node, $container);

            return;
        }

        $this->runRequired(
            $node,
            $this->renderRuntimeConfigWriteScript($container),
            "write managed runtime config for {$container->appSlug()}",
        );
    }

    public function runtimeConfigPath(Node $node, string $appSlug): string
    {
        return $this->nodeHostPaths->appRuntimeConfigPath($node, $appSlug);
    }

    private function ensureNetwork(Node $node, AppRuntimeContainer $container): void
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
    private function inspect(Node $node, AppRuntimeContainer $container): ?array
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

    private function createContainer(Node $node, AppRuntimeContainer $container): void
    {
        $this->runRequired(
            $node,
            $this->renderInstallAndRunScript($container),
            "create {$container->name()} container",
        );
    }

    private function renderInstallAndRunScript(AppRuntimeContainer $container): string
    {
        return $this->renderRuntimeConfigWriteScript($container).PHP_EOL.$this->commands->runDetached($container);
    }

    private function renderRuntimeConfigWriteScript(AppRuntimeContainer $container): string
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

    private function renderRuntimeTrustPoolInstallScript(AppRuntimeContainer $container): string
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

    private function runtimeTrustPoolHostPath(AppRuntimeContainer $container): ?string
    {
        foreach ($container->mounts() as $mount) {
            if ($mount['target'] === AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath) {
                return $mount['source'];
            }
        }

        return null;
    }

    private function renderPackagesMountDirectoryScript(AppRuntimeContainer $container): string
    {
        $sources = [];

        foreach ($container->mounts() as $mount) {
            if ($mount['target'] !== AppDevelopmentPackagesMount::Target) {
                continue;
            }

            $sourceUser = AppDevelopmentPackagesMount::userForSafeSource($mount['source']);

            if ($sourceUser === null) {
                throw new RuntimeException(
                    "App runtime container {$container->name()} has an unsafe packages mount source.",
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

    private function renderConfiguredMountDirectoryScript(AppRuntimeContainer $container): string
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
                    "App runtime container {$container->name()} has an unsafe configured runtime mount source.",
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
    private function builtInMountSources(AppRuntimeContainer $container): array
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
                AppRuntimeContainer::SourceTarget,
                AppRuntimeContainer::PhpIniMountTarget,
                AppDevelopmentPackagesMount::Target,
                AppDevelopmentInnerTlsPolicy::RuntimeTlsCertContainerPath,
                AppDevelopmentInnerTlsPolicy::RuntimeTlsKeyContainerPath,
                AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath,
                '/config',
                '/data',
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

    private function findPhpIniMountSource(AppRuntimeContainer $container): string
    {
        foreach ($container->mounts() as $mount) {
            if ($mount['target'] === AppRuntimeContainer::PhpIniMountTarget) {
                return $mount['source'];
            }
        }

        throw new RuntimeException("App runtime container {$container->name()} is missing its php.ini mount.");
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    private function matchesSpec(array $inspection, AppRuntimeContainer $container): bool
    {
        $labels = $inspection['Config']['Labels'] ?? [];

        if (! is_array($labels)) {
            return false;
        }

        return ($labels[AppRuntimeContainer::SpecHashLabel] ?? null) === $container->specHash();
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
            'orbit-app-runtime',
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
