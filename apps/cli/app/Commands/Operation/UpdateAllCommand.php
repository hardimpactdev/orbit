<?php

declare(strict_types=1);

namespace App\Commands\Operation;

use App\Commands\Concerns\StreamsGatewayProgress;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\GatewayOperationFollower;
use App\Services\Updates\RunsLocalUpdate;
use App\Services\Updates\UpdateAllHumanProgressRenderer;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Progress\ProgressEventType;
use Throwable;

final class UpdateAllCommand extends GatewayCommand
{
    use StreamsGatewayProgress;

    private const string TopologyCandidateManifestSource = 'topology-candidate';

    #[\Override]
    protected $signature = 'update:all {--json : Output JSON}';

    #[\Override]
    protected $description = 'Update every managed Orbit installation through the gateway.';

    public function handle(
        RunsLocalUpdate $localUpdater,
        UpdateAllHumanProgressRenderer $progress,
    ): int {
        if (! $this->wantsJson()) {
            return $this->handleHuman($localUpdater, $progress);
        }

        return $this->handleJson($localUpdater);
    }

    private function handleHuman(
        RunsLocalUpdate $localUpdater,
        UpdateAllHumanProgressRenderer $progress,
    ): int {
        $progress->begin($this->output);
        $progress->checkingForUpdates($this->output);

        $payload = $this->updateStartPayload();

        if (is_int($payload)) {
            return $payload;
        }

        try {
            $response = $this->gatewayPostWithIdleTicks('/api/update/all/start', $payload);
        } catch (GatewayApiException $exception) {
            $progress->gatewayFailed($this->output, $exception->getMessage());
            $progress->finishFailure($this->output);

            return $this->renderGatewayFailure($exception);
        }

        $eventsUrl = $this->eventsUrl($response);

        if ($eventsUrl === null) {
            $progress->gatewayFailed($this->output, 'Gateway update operation did not provide an event stream URL.');
            $progress->finishFailure($this->output);

            return $this->renderFailure(
                'gateway_unavailable',
                'Gateway update operation did not provide an event stream URL.',
            );
        }

        try {
            $terminal = app(GatewayOperationFollower::class)->follow(
                $eventsUrl,
                function (ProgressEventType $type, array $payload) use ($progress): void {
                    if ($type === ProgressEventType::Complete || $type === ProgressEventType::Error) {
                        return;
                    }

                    $progress->applyEvent($this->output, $type, $payload);
                },
            );
        } catch (GatewayApiException $exception) {
            $progress->gatewayFailed($this->output, $exception->getMessage());
            $progress->finishFailure($this->output);

            return $this->renderGatewayFailure($exception);
        }

        if ($terminal['type'] === ProgressEventType::Error) {
            $leaseConflictMessage = $this->humanOperationLeaseConflictMessage($terminal['payload']);

            if ($leaseConflictMessage !== null) {
                $progress->fleetLeaseConflictFailed($this->output, $leaseConflictMessage);
                $progress->finishFailure($this->output);

                return self::FAILURE;
            }

            $progress->gatewayFailed($this->output, $this->operationErrorMessage($terminal['payload']));
            $progress->finishFailure($this->output);

            return $this->renderOperationError($terminal['payload']);
        }

        $targetVersion = $this->terminalTargetVersion($terminal['payload']);
        $allCurrent = $this->terminalAllCurrent($terminal['payload']);
        $reapplyLocal = $this->terminalUsesTopologyCandidateManifest($terminal['payload']);
        $candidateBinaryUrl = $this->terminalCandidateBinaryUrl($terminal['payload']);

        // All-current short-circuit: skip local update; nothing was outdated.
        if ($allCurrent) {
            $progress->finishSuccess($this->output, $targetVersion, allCurrent: true);

            return self::SUCCESS;
        }

        // Gateway phase succeeded — run local update as a fan-out target.
        $this->runLocalFanOut($localUpdater, $progress, $targetVersion, $reapplyLocal, $candidateBinaryUrl);

        $progress->finishSuccess($this->output, $targetVersion);

        return self::SUCCESS;
    }

    private function handleJson(RunsLocalUpdate $localUpdater): int
    {
        $payload = $this->updateStartPayload();

        if (is_int($payload)) {
            return $payload;
        }

        try {
            $response = $this->gatewayPost('/api/update/all/start', $payload);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $eventsUrl = $this->eventsUrl($response);

        if ($eventsUrl === null) {
            return $this->renderFailure(
                'gateway_unavailable',
                'Gateway update operation did not provide an event stream URL.',
            );
        }

        // Capture gateway terminal without rendering yet; we decide what to output
        // based on whether the subsequent local fan-out also succeeds.
        try {
            $terminal = app(GatewayOperationFollower::class)->follow(
                $eventsUrl,
                function (ProgressEventType $type, array $payload): void {
                    // Events are not rendered in JSON mode; only the terminal frame is.
                },
            );
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($terminal['type'] === ProgressEventType::Error) {
            // Gateway failed — output gateway error JSON directly.
            return $this->renderProgressTerminalFrame($terminal['type'], $terminal['payload']);
        }

        // All-current short-circuit: nothing was outdated, so the local update is
        // skipped too. Output the gateway terminal frame directly.
        if ($this->terminalAllCurrent($terminal['payload'])) {
            return $this->renderProgressTerminalFrame($terminal['type'], $terminal['payload']);
        }

        // Local already on the target: skip its download and output the gateway
        // terminal frame directly (mirrors the human-mode local skip).
        if (
            ! $this->terminalUsesTopologyCandidateManifest($terminal['payload'])
            && $this->localIsCurrent($this->terminalTargetVersion($terminal['payload']))
        ) {
            return $this->renderProgressTerminalFrame($terminal['type'], $terminal['payload']);
        }

        // Gateway phase succeeded — run local update as a fan-out target.
        $download = $this->withBinaryUrl(
            $this->terminalCandidateBinaryUrl($terminal['payload']),
            fn (): array => $localUpdater->downloadBinary(),
        );

        if (! $download['successful'] || ! is_string($download['staged_path']) || ! is_string($download['version'])) {
            return $this->renderFailure(
                'local_update_failed',
                'Failed to update local Orbit checkout.',
                ['failed_step' => 'download'],
                $download['output'] !== '' ? ['output' => $download['output']] : [],
            );
        }

        $replace = $localUpdater->replaceBinary($download['staged_path'], $download['version']);

        if (! $replace['successful']) {
            return $this->renderFailure(
                'local_update_failed',
                'Failed to update local Orbit checkout.',
                ['failed_step' => 'replace'],
                $replace['output'] !== '' ? ['output' => $replace['output']] : [],
            );
        }

        $localUpdater->runDoctor();

        // Local succeeded — output the gateway terminal event as the final JSON frame.
        return $this->renderProgressTerminalFrame($terminal['type'], $terminal['payload']);
    }

    /**
     * Run the local CLI update as a fan-out target after the gateway phase,
     * emitting sub-stage rows to the progress renderer. The local row mirrors
     * the workload-node sub-stage vocabulary: Downloading -> Replacing cli
     * binary -> Running doctor -> Done (or `Done (<n> issues)`).
     */
    private function runLocalFanOut(
        RunsLocalUpdate $localUpdater,
        UpdateAllHumanProgressRenderer $progress,
        ?string $targetVersion,
        bool $reapplyLocal,
        ?string $candidateBinaryUrl,
    ): void {
        // Skip the download when the caller-local CLI is already on the target
        // (mirrors the per-node skip; the gateway-first gate is already met).
        if (! $reapplyLocal && $this->localIsCurrent($targetVersion)) {
            $progress->localNodeSkipped($this->output);

            return;
        }

        $progress->localNodeSubStep($this->output, 'downloading', $targetVersion ?? '');

        $download = $this->withBinaryUrl($candidateBinaryUrl, fn (): array => $localUpdater->downloadBinary());

        if (! $download['successful'] || ! is_string($download['staged_path']) || ! is_string($download['version'])) {
            $progress->localNodeFailed($this->output, $download['output']);

            return;
        }

        $progress->localNodeSubStep($this->output, 'replacing_cli_binary');
        $replace = $localUpdater->replaceBinary($download['staged_path'], $download['version']);

        if (! $replace['successful']) {
            $progress->localNodeFailed($this->output, $replace['output']);

            return;
        }

        $progress->localNodeSubStep($this->output, 'running_doctor');
        $doctor = $localUpdater->runDoctor();

        $progress->localNodeSucceeded($this->output, $doctor['issues']);
    }

    /**
     * Whether the caller-local CLI is already on (or ahead of) the target
     * version, so the local fan-out can skip its download.
     */
    private function localIsCurrent(?string $targetVersion): bool
    {
        if ($targetVersion === null || $targetVersion === '') {
            return false;
        }

        $localVersion = (string) config('app.version', '');

        // Only skip on real semantic versions; for anything unparseable (e.g. a
        // synthetic E2E target) run the update rather than risk skipping wrongly.
        if (preg_match('/^\d+\.\d+/', $localVersion) !== 1 || preg_match('/^\d+\.\d+/', $targetVersion) !== 1) {
            return false;
        }

        return version_compare($localVersion, $targetVersion, '>=');
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function eventsUrl(array $response): ?string
    {
        $success = $response['success'] ?? null;
        $data = is_array($success) ? $success['data'] ?? null : null;
        $eventsUrl = is_array($data) ? $data['events_url'] ?? null : null;

        if (! is_string($eventsUrl)) {
            return null;
        }

        $eventsUrl = trim($eventsUrl);

        return $eventsUrl === '' ? null : $eventsUrl;
    }

    /**
     * @return array{manifest: array<string, mixed>}|array{}|int
     */
    private function updateStartPayload(): array|int
    {
        $manifestUrl = $this->configuredReleaseManifestUrl();

        if ($manifestUrl === null) {
            return [];
        }

        $manifest = $this->downloadReleaseManifest($manifestUrl);

        if (is_int($manifest)) {
            return $manifest;
        }

        return ['manifest' => $manifest];
    }

    private function configuredReleaseManifestUrl(): ?string
    {
        $value = getenv('ORBIT_RELEASE_MANIFEST_URL');

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>|int
     */
    private function downloadReleaseManifest(string $url): array|int
    {
        try {
            $response = Http::acceptJson()
                ->timeout($this->releaseManifestTimeoutSeconds())
                ->get($url);
        } catch (Throwable $exception) {
            return $this->renderReleaseManifestUnavailable($url, $exception->getMessage());
        }

        if (! $response->successful()) {
            return $this->renderReleaseManifestUnavailable($url, "HTTP {$response->status()}");
        }

        $manifest = $response->json();

        if (! is_array($manifest)) {
            return $this->renderReleaseManifestUnavailable($url, 'response was not a JSON object');
        }

        return $this->stringKeyedArray($manifest);
    }

    private function releaseManifestTimeoutSeconds(): int
    {
        $value = getenv('ORBIT_RELEASE_MANIFEST_TIMEOUT_SECONDS');

        if (! is_numeric($value)) {
            return 10;
        }

        return max(1, (int) $value);
    }

    private function renderReleaseManifestUnavailable(string $url, string $reason): int
    {
        return $this->renderFailure(
            'release_manifest_unavailable',
            'Release manifest could not be resolved.',
            [
                'url' => $url,
                'reason' => $reason,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderOperationError(array $payload): int
    {
        $data = $this->frameData($payload);
        $code = $this->frameString($data, 'code') ?? $this->frameString($payload, 'code') ?? 'gateway_stream_error';
        $message =
            $this->frameString($data, 'message') ?? $this->frameString($payload, 'message')
                ?? 'Gateway progress stream failed.';
        $meta = $this->frameArray($data, 'meta') ?? $this->frameArray($payload, 'meta') ?? [];

        return $this->renderFailure($code, $message, $meta);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function terminalTargetVersion(array $payload): ?string
    {
        return (
            $this->frameString($this->frameData($payload), 'target_version') ?? $this->frameString(
                $payload,
                'target_version',
            )
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function terminalAllCurrent(array $payload): bool
    {
        $data = $this->frameData($payload);
        $skipped = $data['skipped'] ?? $payload['skipped'] ?? false;

        return $skipped === true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function terminalUsesTopologyCandidateManifest(array $payload): bool
    {
        return (
            $this->frameString($this->frameData($payload), 'manifest_source') === self::TopologyCandidateManifestSource
            || $this->frameString($payload, 'manifest_source') === self::TopologyCandidateManifestSource
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function terminalCandidateBinaryUrl(array $payload): ?string
    {
        if (! $this->terminalUsesTopologyCandidateManifest($payload)) {
            return null;
        }

        $platform = $this->localCliPlatform();

        if ($platform === null) {
            return null;
        }

        $data = $this->frameData($payload);
        $artifacts = $this->frameArray($data, 'cli_artifacts') ?? $this->frameArray($payload, 'cli_artifacts') ?? [];
        $artifact = $artifacts[$platform] ?? null;
        $url = is_array($artifact) ? $artifact['url'] ?? null : null;

        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        return $url === '' ? null : $url;
    }

    private function localCliPlatform(): ?string
    {
        $os = php_uname('s');
        $machine = php_uname('m');

        if (str_starts_with($os, 'Darwin') && $machine === 'arm64') {
            return 'darwin-arm64';
        }

        if (str_starts_with($os, 'Linux') && $machine === 'x86_64') {
            return 'linux-amd64';
        }

        return null;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withBinaryUrl(?string $binaryUrl, callable $callback): mixed
    {
        if ($binaryUrl === null) {
            return $callback();
        }

        $previous = getenv('ORBIT_BINARY_URL');
        putenv("ORBIT_BINARY_URL={$binaryUrl}");

        try {
            return $callback();
        } finally {
            if ($previous === false) {
                putenv('ORBIT_BINARY_URL');
            } else {
                putenv("ORBIT_BINARY_URL={$previous}");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function operationErrorMessage(array $payload): string
    {
        $data = $this->frameData($payload);

        return (
            $this->frameString($data, 'message') ?? $this->frameString($payload, 'message')
            ?? 'Gateway progress stream failed.'
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function humanOperationLeaseConflictMessage(array $payload): ?string
    {
        $data = $this->frameData($payload);
        $code = $this->frameString($data, 'code') ?? $this->frameString($payload, 'code');

        if ($code !== 'update_lease_conflict') {
            return null;
        }

        $resourceType = $this->frameString($data, 'resource_type');

        if (! in_array($resourceType, ['fleet', 'gateway', 'scheduler'], true)) {
            return null;
        }

        $nodeName = $this->frameString($data, 'conflicting_node') ?? 'another node';

        return "Failed: update:all is still being performed by {$nodeName}";
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function frameData(array $payload): array
    {
        $data = $payload['data'] ?? null;

        return is_array($data) ? $data : $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function frameString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function frameArray(array $payload, string $key): ?array
    {
        $value = $payload[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $value): array
    {
        $result = [];

        foreach ($value as $key => $entry) {
            $result[(string) $key] = $entry;
        }

        return $result;
    }
}
