<?php

declare(strict_types=1);

namespace App\Commands\Operation;

use App\Commands\Concerns\StreamsGatewayProgress;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\GatewayOperationFollower;
use App\Services\Updates\RunsLocalUpdate;
use App\Services\Updates\UpdateAllHumanProgressRenderer;
use Orbit\Core\Progress\ProgressEventType;

final class UpdateAllCommand extends GatewayCommand
{
    use StreamsGatewayProgress;

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

        try {
            $response = $this->gatewayPost('/api/update/all/start');
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
            $progress->gatewayFailed($this->output, $this->operationErrorMessage($terminal['payload']));
            $progress->finishFailure($this->output);

            return $this->renderOperationError($terminal['payload']);
        }

        $targetVersion = $this->terminalTargetVersion($terminal['payload']);
        $allCurrent = $this->terminalAllCurrent($terminal['payload']);

        // All-current short-circuit: skip local update; nothing was outdated.
        if ($allCurrent) {
            $progress->finishSuccess($this->output, $targetVersion, allCurrent: true);

            return self::SUCCESS;
        }

        // Gateway phase succeeded — run local update as a fan-out target.
        $this->runLocalFanOut($localUpdater, $progress, $targetVersion);

        $progress->finishSuccess($this->output, $targetVersion);

        return self::SUCCESS;
    }

    private function handleJson(RunsLocalUpdate $localUpdater): int
    {
        try {
            $response = $this->gatewayPost('/api/update/all/start');
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
        if ($this->localIsCurrent($this->terminalTargetVersion($terminal['payload']))) {
            return $this->renderProgressTerminalFrame($terminal['type'], $terminal['payload']);
        }

        // Gateway phase succeeded — run local update as a fan-out target.
        $download = $localUpdater->downloadBinary();

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
    ): void {
        // Skip the download when the caller-local CLI is already on the target
        // (mirrors the per-node skip; the gateway-first gate is already met).
        if ($this->localIsCurrent($targetVersion)) {
            $progress->localNodeSkipped($this->output);

            return;
        }

        $progress->localNodeSubStep($this->output, 'downloading', $targetVersion ?? '');

        $download = $localUpdater->downloadBinary();

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
        $data = is_array($success) ? ($success['data'] ?? null) : null;
        $eventsUrl = is_array($data) ? ($data['events_url'] ?? null) : null;

        if (! is_string($eventsUrl)) {
            return null;
        }

        $eventsUrl = trim($eventsUrl);

        return $eventsUrl === '' ? null : $eventsUrl;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderOperationError(array $payload): int
    {
        $data = $this->frameData($payload);
        $code = $this->frameString($data, 'code') ?? $this->frameString($payload, 'code') ?? 'gateway_stream_error';
        $message = $this->frameString($data, 'message') ?? $this->frameString($payload, 'message') ?? 'Gateway progress stream failed.';
        $meta = $this->frameArray($data, 'meta') ?? $this->frameArray($payload, 'meta') ?? [];

        return $this->renderFailure($code, $message, $meta);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function terminalTargetVersion(array $payload): ?string
    {
        return $this->frameString($this->frameData($payload), 'target_version')
            ?? $this->frameString($payload, 'target_version');
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
    private function operationErrorMessage(array $payload): string
    {
        $data = $this->frameData($payload);

        return $this->frameString($data, 'message')
            ?? $this->frameString($payload, 'message')
            ?? 'Gateway progress stream failed.';
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
}
