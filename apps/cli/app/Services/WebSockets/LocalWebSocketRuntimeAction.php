<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use InvalidArgumentException;
use JsonException;
use Symfony\Component\Process\Process;

final readonly class LocalWebSocketRuntimeAction
{
    private const string RuntimeRoot = '/opt/orbit/websocket';

    private const string AppsConfigPath = '/etc/orbit/websocket/apps.php';

    private const string SourceHostPath = '/opt/orbit/websocket/current';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function run(mixed $action, array $payload = []): array
    {
        return match ($action) {
            'image:ensure' => $this->ensureImage($payload),
            'image:is-self-contained' => $this->imageIsSelfContained(),
            'app-key:ensure' => $this->ensureAppKey(),
            'source:install' => $this->installSource($payload),
            'container:apply' => $this->applyContainer($payload),
            'container:remove' => $this->removeContainer($payload),
            'app-config:sync' => $this->syncAppConfig($payload),
            'doctor:backend-cert-probe' => $this->backendCertificateProbe($payload),
            'doctor:valkey-probe' => $this->valkeyProbe($payload),
            'doctor:runtime-probe' => $this->runtimeProbe($payload),
            default => throw new LocalWebSocketRuntimeFailure(
                errorCode: 'validation_failed',
                message: 'Websocket runtime action is invalid.',
                meta: ['field' => 'action'],
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{image: string, alias: string, self_contained: true}
     */
    private function ensureImage(array $payload): array
    {
        $image = $this->image($payload['image'] ?? null);
        $alias = 'orbit-reverb:current';
        $artifact = $this->imageArtifact($payload['artifact'] ?? null);
        $sourceImage = $artifact === null
            ? $this->pullImage($image)
            : $this->loadImageArtifact($image, $artifact);

        if ($this->imageIsSelfContained($sourceImage)['self_contained'] !== true) {
            throw new LocalWebSocketRuntimeFailure(
                errorCode: 'websocket_runtime_image_invalid',
                message: 'Websocket runtime image is not self-contained.',
                meta: ['image' => $image],
            );
        }

        $tag = $this->runProcess(['docker', 'tag', $sourceImage, $alias]);

        if (! $tag->isSuccessful()) {
            throw new LocalWebSocketRuntimeFailure(
                errorCode: 'websocket_runtime_image_tag_failed',
                message: 'Websocket runtime image alias could not be updated.',
                meta: ['image' => $image, 'alias' => $alias, 'output' => $this->output($tag)],
            );
        }

        return [
            'image' => $image,
            'alias' => $alias,
            'self_contained' => true,
        ];
    }

    private function pullImage(string $image): string
    {
        $pull = $this->runProcess(['docker', 'pull', $image], timeout: 360);

        if (! $pull->isSuccessful()) {
            throw new LocalWebSocketRuntimeFailure(
                errorCode: 'websocket_runtime_image_pull_failed',
                message: 'Websocket runtime image pull failed.',
                meta: ['image' => $image, 'output' => $this->output($pull)],
            );
        }

        return $image;
    }

    /**
     * @param  array{url: string, sha256: string}  $artifact
     */
    private function loadImageArtifact(string $image, array $artifact): string
    {
        $sourceImage = $this->sourceImage($image);
        $archive = tempnam(directory: sys_get_temp_dir(), prefix: 'orbit-websocket-image-');

        if (! is_string($archive)) {
            throw new LocalWebSocketRuntimeFailure(
                errorCode: 'websocket_runtime_image_artifact_failed',
                message: 'Websocket runtime image artifact could not be prepared.',
            );
        }

        try {
            $download = $this->runProcess([
                'curl',
                '--fail',
                '--silent',
                '--show-error',
                '--location',
                '--output',
                $archive,
                $artifact['url'],
            ], timeout: 360);

            if (! $download->isSuccessful()) {
                throw new LocalWebSocketRuntimeFailure(
                    errorCode: 'websocket_runtime_image_artifact_download_failed',
                    message: 'Websocket runtime image artifact download failed.',
                    meta: ['url' => $artifact['url'], 'output' => $this->output($download)],
                );
            }

            $actualSha256 = hash_file('sha256', $archive);

            if (! is_string($actualSha256) || ! hash_equals($artifact['sha256'], $actualSha256)) {
                throw new LocalWebSocketRuntimeFailure(
                    errorCode: 'websocket_runtime_image_artifact_invalid',
                    message: 'Websocket runtime image artifact checksum does not match.',
                    meta: ['url' => $artifact['url']],
                );
            }

            $this->validateImageArchive($archive, $image, $sourceImage);

            $load = $this->runProcess(['docker', 'load', '--input', $archive], timeout: 360);

            if (! $load->isSuccessful()) {
                throw new LocalWebSocketRuntimeFailure(
                    errorCode: 'websocket_runtime_image_artifact_load_failed',
                    message: 'Websocket runtime image artifact could not be loaded.',
                    meta: ['image' => $image, 'output' => $this->output($load)],
                );
            }
        } finally {
            if (is_file($archive)) {
                unlink($archive);
            }
        }

        return $sourceImage;
    }

    private function sourceImage(string $image): string
    {
        $sourceImage = strstr(haystack: $image, needle: '@', before_needle: true);

        if (! is_string($sourceImage) || $sourceImage === '' || $sourceImage === 'orbit-reverb:current') {
            throw new InvalidArgumentException('Websocket runtime image tag is invalid.');
        }

        return $sourceImage;
    }

    private function validateImageArchive(string $archive, string $image, string $sourceImage): void
    {
        $manifest = $this->runProcess([
            'tar',
            '--extract',
            '--to-stdout',
            '--file',
            $archive,
            'manifest.json',
        ]);

        if (! $manifest->isSuccessful()) {
            throw new LocalWebSocketRuntimeFailure(
                errorCode: 'websocket_runtime_image_artifact_invalid',
                message: 'Websocket runtime image artifact does not contain a readable Docker manifest.',
                meta: ['image' => $image],
            );
        }

        try {
            $entries = json_decode($manifest->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new LocalWebSocketRuntimeFailure(
                errorCode: 'websocket_runtime_image_artifact_invalid',
                message: 'Websocket runtime image artifact does not contain a readable Docker manifest.',
                meta: ['image' => $image],
            );
        }

        $tags = [];

        if (is_array($entries) && array_is_list($entries)) {
            foreach ($entries as $entry) {
                $repoTags = is_array($entry) ? $entry['RepoTags'] ?? null : null;

                if (! is_array($repoTags) || ! array_is_list($repoTags)) {
                    $tags = [];
                    break;
                }

                foreach ($repoTags as $tag) {
                    if (! is_string($tag)) {
                        $tags = [];
                        break 2;
                    }

                    $tags[] = $tag;
                }
            }
        }

        if ($tags !== [$sourceImage]) {
            throw new LocalWebSocketRuntimeFailure(
                errorCode: 'websocket_runtime_image_artifact_invalid',
                message: 'Websocket runtime image artifact does not contain exactly the expected image tag.',
                meta: ['image' => $image],
            );
        }
    }

    /**
     * @return array{url: string, sha256: string}|null
     */
    private function imageArtifact(mixed $artifact): ?array
    {
        if ($artifact === null) {
            return null;
        }

        $url = is_array($artifact) ? $artifact['url'] ?? null : null;
        $sha256 = is_array($artifact) ? $artifact['sha256'] ?? null : null;

        if (
            ! is_string($url)
            || $url !== trim($url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || ! is_string($sha256)
            || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1
        ) {
            throw new InvalidArgumentException('Websocket runtime image artifact is invalid.');
        }

        return ['url' => $url, 'sha256' => $sha256];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, source_hash: string, stdout: string}
     */
    private function installSource(array $payload): array
    {
        $sourceHash = $this->sourceHash($payload['source_hash'] ?? null);
        $archive = $this->sourceArchive($payload['archive_base64'] ?? null);
        $releaseDir = self::RuntimeRoot."/releases/{$sourceHash}";
        $sharedDir = self::RuntimeRoot.'/shared';
        $sharedEnv = "{$sharedDir}/.env";
        $timings = [];

        $this->timed($timings, 'setup', function () use ($sharedDir): void {
            $this->mustRun([
                'sudo',
                'install',
                '-d',
                '-m',
                '0755',
                self::RuntimeRoot,
                self::RuntimeRoot.'/releases',
                $sharedDir,
                dirname(self::AppsConfigPath),
            ]);

            if (! $this->runProcess(['sudo', 'test', '-f', self::AppsConfigPath])->isSuccessful()) {
                $this->writeSudoFile(self::AppsConfigPath, "<?php return [];\n");
            }

            $this->mustRun(['sudo', 'chmod', '0600', self::AppsConfigPath]);
        });

        $currentHash = trim(
            $this->runProcess(['sudo', 'cat', "{$releaseDir}/.orbit-websocket-source-hash"])->getOutput(),
        );

        $this->timed($timings, 'extract', function () use ($archive, $currentHash, $releaseDir, $sourceHash): void {
            if (hash_equals($sourceHash, $currentHash)) {
                return;
            }

            $this->mustRun(['sudo', 'rm', '-rf', $releaseDir]);
            $this->mustRun(['sudo', 'install', '-d', '-m', '0755', $releaseDir]);
            $this->mustRunWithInput(['sudo', 'tar', '-xf', '-', '-C', $releaseDir], $archive, 60);
            $this->mustRun(['sudo', 'find', $releaseDir, '-type', 'd', '-exec', 'chmod', '0755', '{}', '+']);
            $this->mustRun(['sudo', 'find', $releaseDir, '-type', 'f', '-exec', 'chmod', '0644', '{}', '+']);
            $this->mustRun(['sudo', 'chmod', '0755', "{$releaseDir}/artisan"]);
        });

        $this->timed($timings, 'env', function () use ($releaseDir, $sharedEnv): void {
            if (! $this->runProcess(['sudo', 'test', '-f', $sharedEnv])->isSuccessful()) {
                $this->writeSudoFile($sharedEnv, 'APP_KEY='.$this->generateAppKey()."\n");
            } else {
                $this->mustRun(['sudo', 'chmod', '0600', $sharedEnv]);

                if (! $this->runProcess(['sudo', 'grep', '-q', '^APP_KEY=', $sharedEnv])->isSuccessful()) {
                    $this->appendSudoFile($sharedEnv, 'APP_KEY='.$this->generateAppKey()."\n");
                }
            }

            $this->mustRun(['sudo', 'chmod', '0600', $sharedEnv]);
            $this->mustRun(['sudo', 'ln', '-sfn', $sharedEnv, "{$releaseDir}/.env"]);
        });

        $this->timed($timings, 'composer', function () use ($releaseDir): void {
            if ($this->runProcess(['sudo', 'test', '-f', "{$releaseDir}/vendor/autoload.php"])->isSuccessful()) {
                return;
            }

            if (! $this->runProcess(['composer', '--version'])->isSuccessful()) {
                throw new LocalWebSocketRuntimeFailure(
                    errorCode: 'websocket_runtime_composer_missing',
                    message: 'WebSocket runtime dependencies require host composer.',
                );
            }

            $this->mustRun([
                'sudo',
                'env',
                'COMPOSER_ALLOW_SUPERUSER=1',
                'composer',
                '--working-dir',
                $releaseDir,
                'install',
                '--no-dev',
                '--no-interaction',
                '--prefer-dist',
                '--optimize-autoloader',
                '--no-progress',
            ]);
        });

        $this->timed($timings, 'activate', function () use ($releaseDir, $sourceHash): void {
            $this->writeSudoFile("{$releaseDir}/.orbit-websocket-source-hash", "{$sourceHash}\n");
            $this->mustRun(['sudo', 'ln', '-sfn', "releases/{$sourceHash}", self::SourceHostPath]);
        });

        return [
            'action' => 'source:install',
            'source_hash' => $sourceHash,
            'stdout' => $this->timingOutput($timings),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyContainer(array $payload): array
    {
        $spec = LocalWebSocketRuntimeContainerSpec::from($payload['spec'] ?? null);

        $this->ensureNetwork($spec->network);
        $inspection = $this->inspectContainer($spec->name);
        $hadExistingContainer = $inspection !== null;
        $observedHash = $this->observedSpecHash($inspection);

        if (
            $hadExistingContainer
            && hash_equals($spec->expectedHash, $observedHash ?? '')
            && $this->isRunning($inspection)
        ) {
            return $this->containerApplyResult($spec->name, 'unchanged', true, false);
        }

        if ($hadExistingContainer && ! hash_equals($spec->expectedHash, $observedHash ?? '')) {
            $remove = $this->runProcess(['docker', 'rm', '-f', $spec->name]);

            if (! $remove->isSuccessful()) {
                throw $this->containerFailure('remove drifted', $spec->name, $remove);
            }

            $create = $this->runProcess($spec->runCommand());

            if (! $create->isSuccessful()) {
                throw $this->containerFailure('create', $spec->name, $create);
            }

            return $this->containerApplyResult($spec->name, 'recreated', true, true);
        }

        if (! $hadExistingContainer) {
            $create = $this->runProcess($spec->runCommand());

            if (! $create->isSuccessful()) {
                throw $this->containerFailure('create', $spec->name, $create);
            }

            return $this->containerApplyResult($spec->name, 'created', false, true);
        }

        $start = $this->runProcess(['docker', 'start', $spec->name]);

        if (! $start->isSuccessful()) {
            throw $this->containerFailure('start', $spec->name, $start);
        }

        return $this->containerApplyResult($spec->name, 'started', true, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, container: string, changed: bool}
     */
    private function removeContainer(array $payload): array
    {
        $container = $this->container($payload['container'] ?? null);
        $inspect = $this->runProcess(['docker', 'container', 'inspect', $container]);

        if (! $inspect->isSuccessful()) {
            if ($this->isDockerNoSuchContainer($inspect)) {
                return [
                    'action' => 'container:remove',
                    'container' => $container,
                    'changed' => false,
                ];
            }

            throw $this->containerFailure('inspect', $container, $inspect);
        }

        $remove = $this->runProcess(['docker', 'rm', '-f', $container]);

        if (! $remove->isSuccessful()) {
            throw $this->containerFailure('remove', $container, $remove);
        }

        return [
            'action' => 'container:remove',
            'container' => $container,
            'changed' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{exists: string, running: string, env_host: string, cmd_host: string, published_bindings: string, stdout: string}
     */
    private function runtimeProbe(array $payload): array
    {
        $container = $this->container($payload['container'] ?? null);
        $inspect = $this->runProcess(['docker', 'container', 'inspect', $container]);

        if (! $inspect->isSuccessful()) {
            return $this->runtimeProbePayload([
                'exists' => '0',
                'running' => 'false',
                'env_host' => '',
                'cmd_host' => '',
                'published_bindings' => '[]',
            ]);
        }

        $running = $this->inspectValue($container, '{{.State.Running}}');
        $env = $this->inspectValue($container, '{{range .Config.Env}}{{println .}}{{end}}');
        $command = $this->inspectValue($container, '{{range .Config.Cmd}}{{print . " "}}{{end}}');
        $publication = $this->inspectValue(
            $container,
            '{{with (index .NetworkSettings.Ports "8080/tcp")}}{{range .}}{{println .HostIp "|" .HostPort}}{{end}}{{end}}',
        );
        $publishedBindings = json_encode($this->publishedAddresses($publication), JSON_THROW_ON_ERROR);

        return $this->runtimeProbePayload([
            'exists' => '1',
            'running' => $running !== '' ? $running : 'false',
            'env_host' => $this->envValue($env, 'REVERB_SERVER_HOST'),
            'cmd_host' => $this->commandHost($command),
            'published_bindings' => $publishedBindings,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{cert_exists: string, key_exists: string, cert_matches: string, stdout: string}
     */
    private function backendCertificateProbe(array $payload): array
    {
        $backend = $this->backend($payload['backend'] ?? null);
        $cert = $this->absolutePath($payload['cert'] ?? null, 'cert');
        $key = $this->absolutePath($payload['key'] ?? null, 'key');
        $certExists = $this->runProcess(['sudo', 'test', '-f', $cert])->isSuccessful() ? '1' : '0';
        $keyExists = $this->runProcess(['sudo', 'test', '-f', $key])->isSuccessful() ? '1' : '0';
        $certMatches = '';

        if ($certExists === '1') {
            $result = $this->runProcess([
                'sudo',
                'openssl',
                'x509',
                '-in',
                $cert,
                '-noout',
                '-subject',
                '-ext',
                'subjectAltName',
            ]);
            $certMatches = $result->isSuccessful() && str_contains($result->getOutput(), $backend) ? '1' : '0';
        }

        return [
            'cert_exists' => $certExists,
            'key_exists' => $keyExists,
            'cert_matches' => $certMatches,
            'stdout' => "cert_exists={$certExists}\nkey_exists={$keyExists}\ncert_matches={$certMatches}\n",
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: true}
     */
    private function valkeyProbe(array $payload): array
    {
        $container = $this->container($payload['container'] ?? null);
        $process = new Process(['docker', 'exec', '-i', $container, 'php']);
        $process->setInput(<<<'PHP'
            <?php

            $host = getenv('REDIS_HOST') ?: 'valkey.orbit';
            $port = (int) (getenv('REDIS_PORT') ?: 6379);
            $errno = 0;
            $errstr = '';
            $socket = @fsockopen($host, $port, $errno, $errstr, 2);

            if (! $socket) {
                fwrite(STDERR, $errstr !== '' ? $errstr : 'valkey unavailable');
                exit(1);
            }

            fclose($socket);
            PHP);
        $process->setTimeout(15);
        $process->run();

        if ($process->isSuccessful()) {
            return ['ok' => true];
        }

        throw new LocalWebSocketRuntimeFailure(
            errorCode: 'websocket_runtime_valkey_unavailable',
            message: 'Websocket runtime Valkey probe failed.',
            meta: [
                'exit_code' => $process->getExitCode(),
                'output' => $this->output($process),
            ],
        );
    }

    /**
     * @return array{self_contained: bool, output: string}
     */
    private function imageIsSelfContained(string $image = 'orbit-reverb:current'): array
    {
        $result = $this->runProcess([
            'docker',
            'image',
            'inspect',
            '--format',
            '{{ index .Config.Labels "orbit.websocket.self_contained" }}',
            $image,
        ]);

        return [
            'self_contained' => $result->isSuccessful() && trim($result->getOutput()) === 'true',
            'output' => $this->output($result),
        ];
    }

    private function image(mixed $image): string
    {
        if (! is_string($image)) {
            throw new InvalidArgumentException('Websocket runtime image must be a string.');
        }

        $trimmedImage = trim($image);

        if (
            $image !== $trimmedImage
            || preg_match('/\A[^@\s]+@sha256:[0-9a-f]{64}\z/i', $image) !== 1
        ) {
            throw new InvalidArgumentException(
                'Websocket runtime image must be a digest-pinned Docker image reference.',
            );
        }

        return $image;
    }

    /**
     * @return array{app_key: string}
     */
    private function ensureAppKey(): array
    {
        $this->mustRun(['sudo', 'install', '-d', '-m', '0755', '/etc/orbit/websocket']);

        $test = $this->runProcess(['sudo', 'test', '-f', '/etc/orbit/websocket/app.key']);

        if (! $test->isSuccessful()) {
            $appKey = $this->generateAppKey();
            $this->writeAppKey($appKey);
        }

        $this->mustRun(['sudo', 'chmod', '0600', '/etc/orbit/websocket/app.key']);
        $cat = $this->mustRun(['sudo', 'cat', '/etc/orbit/websocket/app.key']);
        $appKey = trim($cat->getOutput());

        if ($appKey === '') {
            throw new LocalWebSocketRuntimeFailure(
                errorCode: 'websocket_runtime_app_key_empty',
                message: 'Websocket runtime app key is empty.',
            );
        }

        return ['app_key' => $appKey];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{path: string, bytes: int, restarted: bool}
     */
    private function syncAppConfig(array $payload): array
    {
        $content = $this->content($payload['content'] ?? null);
        $container = $this->container($payload['container'] ?? null);

        $this->mustRun(['sudo', 'install', '-d', '-m', '0755', '/etc/orbit/websocket']);
        $this->writeAppsConfig($content);
        $this->mustRun(['sudo', 'chmod', '0600', self::AppsConfigPath]);

        $inspect = $this->runProcess(['docker', 'container', 'inspect', $container]);

        if (! $inspect->isSuccessful()) {
            return [
                'path' => self::AppsConfigPath,
                'bytes' => strlen($content),
                'restarted' => false,
            ];
        }

        $this->mustRun(['docker', 'restart', $container]);

        return [
            'path' => self::AppsConfigPath,
            'bytes' => strlen($content),
            'restarted' => true,
        ];
    }

    private function inspectValue(string $container, string $format): string
    {
        $result = $this->runProcess(['docker', 'container', 'inspect', '--format', $format, $container]);

        if (! $result->isSuccessful()) {
            return '';
        }

        return trim($result->getOutput());
    }

    private function ensureNetwork(string $network): void
    {
        $inspect = $this->runProcess(['docker', 'network', 'inspect', $network]);

        if ($inspect->isSuccessful()) {
            return;
        }

        $create = $this->runProcess([
            'docker',
            'network',
            'create',
            '--label',
            'orbit.managed=true',
            '--label',
            'orbit.network.kind=runtime',
            $network,
        ]);

        if ($create->isSuccessful()) {
            return;
        }

        throw $this->containerFailure('create network', $network, $create);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function inspectContainer(string $container): ?array
    {
        $inspect = $this->runProcess(['docker', 'container', 'inspect', '--format', '{{json .}}', $container]);

        if (! $inspect->isSuccessful()) {
            if ($this->isDockerNoSuchContainer($inspect)) {
                return null;
            }

            throw $this->containerFailure('inspect', $container, $inspect);
        }

        $output = trim($inspect->getOutput());

        if ($output === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        if (is_array($decoded)) {
            return $decoded;
        }

        throw new LocalWebSocketRuntimeFailure(
            errorCode: 'websocket_runtime_container_inspect_failed',
            message: "Docker returned an invalid inspect payload for '{$container}'.",
            meta: [
                'action' => 'container:apply',
                'container' => $container,
            ],
        );
    }

    /**
     * @param  array<array-key, mixed>|null  $inspection
     */
    private function observedSpecHash(?array $inspection): ?string
    {
        if (
            ! isset($inspection['Config'])
            || ! is_array($inspection['Config'])
            || ! isset($inspection['Config']['Labels'])
            || ! is_array($inspection['Config']['Labels'])
        ) {
            return null;
        }

        return is_string($inspection['Config']['Labels'][LocalWebSocketRuntimeContainerSpec::SpecHashLabel] ?? null)
            ? $inspection['Config']['Labels'][LocalWebSocketRuntimeContainerSpec::SpecHashLabel]
            : null;
    }

    /**
     * @param  array<array-key, mixed>|null  $inspection
     */
    private function isRunning(?array $inspection): bool
    {
        if (! is_array($inspection)) {
            return false;
        }

        return ($inspection['State']['Running'] ?? false) === true;
    }

    /**
     * @return array{action: string, container: string, outcome: string, had_existing_container: bool, changed: bool}
     */
    private function containerApplyResult(
        string $container,
        string $outcome,
        bool $hadExistingContainer,
        bool $changed,
    ): array {
        return [
            'action' => 'container:apply',
            'container' => $container,
            'outcome' => $outcome,
            'had_existing_container' => $hadExistingContainer,
            'changed' => $changed,
        ];
    }

    private function containerFailure(string $action, string $container, Process $result): LocalWebSocketRuntimeFailure
    {
        $output = trim($result->getErrorOutput().' '.$result->getOutput());
        $message = $output !== '' ? $output : 'unknown error';

        return new LocalWebSocketRuntimeFailure(
            errorCode: 'websocket_runtime_container_failed',
            message: "Failed to {$action} websocket runtime container '{$container}': {$message}",
            meta: [
                'action' => $action,
                'container' => $container,
                'exit_code' => $result->getExitCode(),
                'stderr' => trim($result->getErrorOutput()),
            ],
        );
    }

    private function isDockerNoSuchContainer(Process $result): bool
    {
        return preg_match('/No such (object|container)/i', $result->getErrorOutput().' '.$result->getOutput()) === 1;
    }

    /**
     * @param  array{exists: string, running: string, env_host: string, cmd_host: string, published_bindings: string}  $state
     * @return array{exists: string, running: string, env_host: string, cmd_host: string, published_bindings: string, stdout: string}
     */
    private function runtimeProbePayload(array $state): array
    {
        return [
            ...$state,
            'stdout' => "exists={$state['exists']}\nrunning={$state['running']}\nenv_host={$state['env_host']}\ncmd_host={$state['cmd_host']}\npublished_bindings={$state['published_bindings']}\n",
        ];
    }

    /**
     * @return list<array{host: string, port: string}>
     */
    private function publishedAddresses(string $publication): array
    {
        $bindings = [];
        $lines = preg_split('/\R/', trim($publication));

        foreach ($lines !== false ? $lines : [] as $line) {
            $line = trim($line);

            if ($line === '' || ! str_contains($line, '|')) {
                continue;
            }

            [$host, $port] = array_pad(
                explode(separator: '|', string: $line, limit: 2),
                length: 2,
                value: '',
            );
            $bindings[] = [
                'host' => trim($host),
                'port' => trim($port),
            ];
        }

        return $bindings;
    }

    private function envValue(string $env, string $name): string
    {
        foreach (preg_split('/\R/', $env) ?: [] as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');

            if ($key === $name) {
                return $value;
            }
        }

        return '';
    }

    private function commandHost(string $command): string
    {
        $matches = [];

        if (preg_match('/(?:^|\s)--host=([^\s]+)/', $command, $matches) !== 1) {
            return '';
        }

        return $matches[1];
    }

    private function generateAppKey(): string
    {
        try {
            return 'base64:'.base64_encode(random_bytes(32));
        } catch (\Random\RandomException $exception) {
            throw new LocalWebSocketRuntimeFailure(
                errorCode: 'websocket_runtime_app_key_failed',
                message: 'Could not generate websocket runtime app key.',
                previous: $exception,
            );
        }
    }

    private function writeAppsConfig(string $content): void
    {
        $this->writeSudoFile(self::AppsConfigPath, $content);
    }

    private function writeAppKey(string $appKey): void
    {
        $this->writeSudoFile('/etc/orbit/websocket/app.key', "{$appKey}\n");
    }

    private function writeSudoFile(string $path, string $content): void
    {
        $this->mustRun(['sudo', 'install', '-m', '0600', '/dev/null', $path]);

        $process = new Process(['sudo', 'tee', $path]);
        $process->setInput($content);
        $process->setTimeout(10);
        $process->run();

        if ($process->isSuccessful()) {
            return;
        }

        throw new LocalWebSocketRuntimeFailure(
            errorCode: 'websocket_runtime_file_failed',
            message: 'Could not write websocket runtime file.',
            meta: [
                'path' => $path,
                'exit_code' => $process->getExitCode(),
                'output' => $this->output($process),
            ],
        );
    }

    private function appendSudoFile(string $path, string $content): void
    {
        $this->mustRun(['sudo', 'chmod', '0600', $path]);

        $process = new Process(['sudo', 'tee', '-a', $path]);
        $process->setInput($content);
        $process->setTimeout(10);
        $process->run();

        if ($process->isSuccessful()) {
            return;
        }

        throw new LocalWebSocketRuntimeFailure(
            errorCode: 'websocket_runtime_file_failed',
            message: 'Could not append websocket runtime file.',
            meta: [
                'path' => $path,
                'exit_code' => $process->getExitCode(),
                'output' => $this->output($process),
            ],
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRun(array $command): Process
    {
        $result = $this->runProcess($command);

        if ($result->isSuccessful()) {
            return $result;
        }

        throw new LocalWebSocketRuntimeFailure(
            errorCode: 'websocket_runtime_command_failed',
            message: 'Websocket runtime command failed.',
            meta: [
                'command' => $command[0],
                'exit_code' => $result->getExitCode(),
                'output' => $this->output($result),
            ],
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRunWithInput(array $command, string $input, int $timeout): Process
    {
        $process = new Process($command);
        $process->setInput($input);
        $process->setTimeout($timeout);
        $process->run();

        if ($process->isSuccessful()) {
            return $process;
        }

        throw new LocalWebSocketRuntimeFailure(
            errorCode: 'websocket_runtime_command_failed',
            message: 'Websocket runtime command failed.',
            meta: [
                'command' => $command[0],
                'exit_code' => $process->getExitCode(),
                'output' => $this->output($process),
            ],
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command, int $timeout = 15): Process
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }

    private function content(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        throw new LocalWebSocketRuntimeFailure(
            errorCode: 'validation_failed',
            message: 'Websocket runtime app config content is invalid.',
            meta: ['field' => 'content'],
        );
    }

    private function sourceHash(mixed $value): string
    {
        if (is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1) {
            return $value;
        }

        throw new LocalWebSocketRuntimeFailure(
            errorCode: 'validation_failed',
            message: 'Websocket runtime source hash is invalid.',
            meta: ['field' => 'source_hash'],
        );
    }

    private function sourceArchive(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw new LocalWebSocketRuntimeFailure(
                errorCode: 'validation_failed',
                message: 'Websocket runtime source archive is invalid.',
                meta: ['field' => 'archive_base64'],
            );
        }

        $archive = base64_decode($value, strict: true);

        if (is_string($archive) && $archive !== '') {
            return $archive;
        }

        throw new LocalWebSocketRuntimeFailure(
            errorCode: 'validation_failed',
            message: 'Websocket runtime source archive is invalid.',
            meta: ['field' => 'archive_base64'],
        );
    }

    /**
     * @param  array<string, int>  $timings
     */
    private function timed(array &$timings, string $step, callable $callback): void
    {
        $start = hrtime(true);

        $callback();

        $timings[$step] = (int) round((hrtime(true) - $start) / 1_000_000);
    }

    /**
     * @param  array<string, int>  $timings
     */
    private function timingOutput(array $timings): string
    {
        $lines = [];

        foreach ($timings as $step => $duration) {
            $lines[] = "__orbit_websocket_source_timing {$step} {$duration}";
        }

        return implode("\n", $lines)."\n";
    }

    private function container(mixed $value): string
    {
        if (is_string($value) && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/', $value) === 1) {
            return $value;
        }

        throw new LocalWebSocketRuntimeFailure(
            errorCode: 'validation_failed',
            message: 'Websocket runtime container is invalid.',
            meta: ['field' => 'container'],
        );
    }

    private function backend(mixed $value): string
    {
        if (is_string($value) && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/', $value) === 1) {
            return $value;
        }

        throw new LocalWebSocketRuntimeFailure(
            errorCode: 'validation_failed',
            message: 'Websocket backend name is invalid.',
            meta: ['field' => 'backend'],
        );
    }

    private function absolutePath(mixed $value, string $field): string
    {
        if (is_string($value) && str_starts_with($value, '/') && ! str_contains($value, "\0")) {
            return $value;
        }

        throw new LocalWebSocketRuntimeFailure(
            errorCode: 'validation_failed',
            message: 'Websocket runtime path is invalid.',
            meta: ['field' => $field],
        );
    }

    private function output(Process $process): string
    {
        $output = trim($process->getErrorOutput());

        if ($output !== '') {
            return $output;
        }

        return trim($process->getOutput());
    }
}
