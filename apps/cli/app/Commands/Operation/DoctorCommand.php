<?php

declare(strict_types=1);

namespace App\Commands\Operation;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\Concerns\StreamsGatewayProgress;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;
use App\Services\Doctor\DoctorPanelRenderer;
use App\Services\Doctor\DoctorTerminalFrameExtractor;
use App\Services\Doctor\InteractiveDoctorIssueSelector;
use App\Services\GatewayStreamClient;
use App\Services\StreamJsonIdleStepWriter;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Progress\ProgressEvent;
use Orbit\Core\Progress\ProgressEventType;
use Symfony\Component\Console\Output\StreamOutput;
use Throwable;

final class DoctorCommand extends GatewayCommand
{
    use ResolvesHostContext;
    use StreamsGatewayProgress;

    private const int STREAM_JSON_IDLE_PROGRESS_INTERVAL_MICROSECONDS = 1_000_000;

    #[\Override]
    protected $signature = 'doctor
        {--instance= : Limit to one instance}
        {--workspace= : Limit to one workspace}
        {--node= : Target node name}
        {--self : Limit to the calling node identity}
        {--all : Run across all eligible active role nodes}
        {--family=* : Scope to one or more state families}
        {--key= : Limit reported drift to one exact doctor issue key}
        {--fix : Enter resolution mode}
        {--restore : Bulk restore gateway configuration to nodes}
        {--adopt : Bulk adopt node reality into gateway configuration}
        {--dry-run : Preview bulk restore or adopt actions without applying changes}
        {--json : Output JSON}
        {--stream-json : Stream progress frames as newline-delimited JSON}';

    #[\Override]
    protected $description = 'Check Orbit health and diagnose drift through the gateway.';

    private int $doctorPanelLineCount = 0;

    private int $doctorPanelRewindExtraLines = 0;

    private int $doctorPanelFrame = 0;

    /** @var array<string, mixed>|null */
    private ?array $latestDoctorProgressReport = null;

    private bool $fleetHumanStream = false;

    /** @var list<string> */
    private array $fleetNodeOrder = [];

    /** @var array<string, string> */
    private array $fleetNodePhases = [];

    public function handle(): int
    {
        if ((bool) $this->option('json') && $this->wantsStreamJson()) {
            return $this->renderFailure(
                'validation_failed',
                'doctor --json and --stream-json cannot be combined.',
                ['fields' => ['json', 'stream-json']],
            );
        }

        $mode = $this->mode();

        if (is_int($mode)) {
            return $mode;
        }

        if ((bool) $this->option('dry-run') && ! in_array($mode, ['restore', 'adopt'], true)) {
            return $this->renderFailure(
                'validation_failed',
                '--dry-run requires --restore or --adopt.',
                ['fields' => ['dry-run']],
            );
        }

        if ((bool) $this->option('self') && $this->stringOption('node') !== null) {
            return $this->renderFailure(
                'validation_failed',
                '--self and --node are mutually exclusive.',
                ['fields' => ['self', 'node']],
            );
        }

        $scopeValidation = $this->validateDoctorScopeOptions($mode);

        if (is_int($scopeValidation)) {
            return $scopeValidation;
        }

        if ($mode === 'interactive') {
            if ($this->wantsStreamJson()) {
                return $this->renderFailure(
                    'validation_failed',
                    'doctor --fix cannot run with --stream-json because it requires interactive prompts.',
                    ['field' => 'stream-json'],
                );
            }

            if ($this->wantsJson()) {
                return $this->renderFailure(
                    'validation_failed',
                    'doctor --fix cannot run with --json because it requires interactive prompts.',
                    ['field' => 'fix'],
                );
            }

            if (! $this->input->isInteractive()) {
                return $this->renderFailure(
                    'validation_failed',
                    'doctor --fix requires an interactive terminal.',
                    ['field' => 'fix'],
                );
            }

            return $this->runInteractiveDoctor();
        }

        $path = $mode === 'verify' ? '/api/doctor/run' : '/api/doctor/fix';
        $payload = $this->payload($mode);

        if (is_int($payload)) {
            return $payload;
        }

        if ($this->wantsStreamJson()) {
            return $this->streamDoctorJson($path, $payload);
        }

        if ($this->wantsJson()) {
            return $this->streamProgress(
                $path,
                $payload,
                fn (ProgressEventType $type, array $payload): int => $this->renderProgressTerminalFrame(
                    $type,
                    $payload,
                ),
            );
        }

        $frame = $this->captureDoctorProgressTerminalFrame($path, $payload);

        if (is_int($frame)) {
            return $frame;
        }

        return $this->renderDoctorPanel($frame['type'], $frame['payload']);
    }

    /**
     * Render the framed doctor panel for a captured terminal frame in human
     * mode. When the frame carries a `data.doctor` report, the panel replaces
     * the generic step-tree footer; otherwise the shared progress/failure
     * rendering is used (pre-panel failures keep the prose failure style).
     *
     * @param  array<string, mixed>  $payload
     */
    private function renderDoctorPanel(ProgressEventType $type, array $payload, ?string $modeOverride = null): int
    {
        $report = app(DoctorTerminalFrameExtractor::class)->doctor([
            'type' => $type,
            'payload' => $payload,
        ]);

        if ($report === null) {
            return $this->renderProgressTerminalFrame($type, $payload);
        }

        if ($modeOverride !== null) {
            $report['mode'] = $modeOverride;
        }

        $this->writeDoctorPanel($report);

        return new ProgressEvent($type, $payload)->isSuccessfulTerminal() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Stream doctor progress frames, render each doctor snapshot immediately,
     * and return the terminal frame so the final result panel keeps the same
     * success/failure semantics as the shared progress stream contract.
     *
     * @param  array<string, mixed>  $payload
     * @return array{type: ProgressEventType, payload: array<string, mixed>}|int
     */
    private function captureDoctorProgressTerminalFrame(string $path, array $payload): array|int
    {
        $client = app(GatewayStreamClient::class);
        $frames = app(DoctorTerminalFrameExtractor::class);
        $renderedDoctorFrame = false;
        $this->latestDoctorProgressReport = null;
        $this->fleetHumanStream = (bool) $this->option('all');
        $this->fleetNodeOrder = [];
        $this->fleetNodePhases = [];

        $finalType = null;
        $finalPayload = [];

        try {
            $client->streamEvents(
                path: $path,
                payload: $payload,
                onEvent: function (ProgressEventType $type, array $eventPayload) use (
                    $frames,
                    &$renderedDoctorFrame,
                    &$finalType,
                    &$finalPayload,
                ): void {
                    if ($this->captureTerminalDoctorFrame($type, $eventPayload, $finalType, $finalPayload)) {
                        return;
                    }

                    if ($this->captureFleetProgressTree($type, $eventPayload)) {
                        return;
                    }

                    $fleetStepRendered = $this->captureFleetProgressStep($type, $eventPayload, $frames);

                    if ($fleetStepRendered !== null) {
                        $renderedDoctorFrame = $renderedDoctorFrame || $fleetStepRendered;

                        return;
                    }

                    if ($this->captureDoctorProgressSnapshot($type, $eventPayload, $frames)) {
                        $renderedDoctorFrame = true;

                        return;
                    }

                    if (! $renderedDoctorFrame && ! $this->fleetHumanStream) {
                        $this->renderProgressFrame($type, $eventPayload);
                    }
                },
                onIdle: function (): void {
                    $latestDoctorReport = $this->latestDoctorProgressReport;

                    if ($latestDoctorReport === null) {
                        return;
                    }

                    $this->writeDoctorProgressPanel($latestDoctorReport);
                },
            );
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($finalType instanceof ProgressEventType) {
            $terminal = new ProgressEvent($finalType, $finalPayload);

            if ($this->progressTree?->isStarted()) {
                $data = $this->frameData($finalPayload);
                $footer = $this->frameString($data, 'footer') ?? $this->frameString($finalPayload, 'footer');

                $this->progressTree->finish(
                    $footer ?? ($terminal->isSuccessfulTerminal() ? 'Done' : 'Failed'),
                    success: $terminal->isSuccessfulTerminal(),
                );

                if ($this->output->isDecorated() && $this->doctorPanelLineCount > 0) {
                    $this->doctorPanelRewindExtraLines++;
                }
            }

            return [
                'type' => $finalType,
                'payload' => $finalPayload,
            ];
        }

        return $this->renderFailure(
            'gateway_unavailable',
            'Gateway progress stream closed without a terminal frame.',
        );
    }

    /**
     * @param  array<string, mixed>  $eventPayload
     * @param  array<string, mixed>  $finalPayload
     */
    private function captureTerminalDoctorFrame(
        ProgressEventType $type,
        array $eventPayload,
        ?ProgressEventType &$finalType,
        array &$finalPayload,
    ): bool {
        if ($type !== ProgressEventType::Complete && $type !== ProgressEventType::Error) {
            return false;
        }

        $finalType = $type;
        $finalPayload = $eventPayload;

        return true;
    }

    /**
     * @param  array<string, mixed>  $eventPayload
     */
    private function captureFleetProgressTree(ProgressEventType $type, array $eventPayload): bool
    {
        if (! $this->fleetHumanStream || $type !== ProgressEventType::Tree) {
            return false;
        }

        $steps = is_array($eventPayload['steps'] ?? null) ? $eventPayload['steps'] : [];

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $nodeName = $step['key'] ?? null;

            if (! is_string($nodeName) || trim($nodeName) === '') {
                continue;
            }

            $this->fleetNodeOrder[] = $nodeName;
            $this->fleetNodePhases[$nodeName] = 'queued';
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $eventPayload
     */
    private function captureFleetProgressStep(
        ProgressEventType $type,
        array $eventPayload,
        DoctorTerminalFrameExtractor $frames,
    ): ?bool {
        if (! $this->fleetHumanStream || $type !== ProgressEventType::Step) {
            return null;
        }

        $nodeName = $eventPayload['key'] ?? null;
        $status = $eventPayload['status'] ?? null;
        $hasNodeStatus = is_string($nodeName) && trim($nodeName) !== '' && is_string($status) && trim($status) !== '';

        if ($hasNodeStatus) {
            $this->fleetNodePhases[$nodeName] = match (trim($status)) {
                'running', 'progress', 'checking' => 'checking',
                'done', 'ok' => 'done',
                default => trim($status),
            };
        }

        if ($this->captureDoctorProgressSnapshot($type, $eventPayload, $frames)) {
            return true;
        }

        if (! $hasNodeStatus) {
            return false;
        }

        $this->latestDoctorProgressReport = $this->syntheticFleetProgressReport();
        $this->writeDoctorProgressPanel($this->latestDoctorProgressReport);

        return true;
    }

    /**
     * @param  array<string, mixed>  $eventPayload
     */
    private function captureDoctorProgressSnapshot(
        ProgressEventType $type,
        array $eventPayload,
        DoctorTerminalFrameExtractor $frames,
    ): bool {
        $doctor = $frames->doctor([
            'type' => $type,
            'payload' => $eventPayload,
        ]);

        if ($doctor === null) {
            return false;
        }

        $this->latestDoctorProgressReport = $doctor;
        $this->writeDoctorProgressPanel($doctor);

        return true;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeDoctorPanel(array $report): void
    {
        $lines = app(DoctorPanelRenderer::class)->lines($report, $this->doctorPanelFrame);
        $this->doctorPanelFrame++;

        if ($this->output->isDecorated() && $this->doctorPanelLineCount > 0) {
            $previousLineCount = $this->doctorPanelLineCount;
            $renderedLineCount = max($previousLineCount, count($lines));
            $rewindLineCount = $previousLineCount + $this->doctorPanelRewindExtraLines;

            $this->output->write("\e[{$rewindLineCount}A");

            for ($index = 0; $index < $renderedLineCount; $index++) {
                $line = $lines[$index] ?? '';

                $this->output->write("\e[2K\r{$line}\n");
            }

            $this->doctorPanelLineCount = $renderedLineCount;
            $this->doctorPanelRewindExtraLines = 0;

            return;
        }

        foreach ($lines as $line) {
            $this->line($line);
        }

        $this->doctorPanelLineCount = count($lines);
        $this->doctorPanelRewindExtraLines = 0;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeDoctorProgressPanel(array $report): void
    {
        if (! $this->output->isDecorated()) {
            return;
        }

        $this->writeDoctorPanel($report);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function isFleetReport(array $report): bool
    {
        $scope = is_array($report['scope'] ?? null) ? $report['scope'] : [];

        return ($scope['role'] ?? null) === 'fleet';
    }

    /**
     * @return array<string, mixed>
     */
    private function syntheticFleetProgressReport(): array
    {
        $baseReport = $this->isFleetReport($this->latestDoctorProgressReport ?? [])
            ? $this->latestDoctorProgressReport
            : null;
        $progressNodes = $this->syntheticFleetProgressNodes();

        if ($baseReport !== null) {
            $baseReport['progress'] = [
                'state' => 'running',
                'nodes' => $progressNodes,
            ];

            return $baseReport;
        }

        $nodes = [];

        foreach ($this->fleetNodeOrder as $nodeName) {
            $nodes[] = [
                'node' => $nodeName,
                'role' => 'unknown',
                'healthy' => true,
                'families' => $this->families(),
                'summary' => ['issues' => 0],
            ];
        }

        return [
            'healthy' => false,
            'mode' => 'verify',
            'scope' => [
                'families' => $this->families(),
                'node' => null,
                'role' => 'fleet',
                'self' => false,
                'app' => null,
                'instance' => null,
                'workspace' => null,
                'key' => $this->option('key'),
                'targets' => $this->fleetNodeOrder,
            ],
            'summary' => [
                'issues' => 0,
                'fixed' => 0,
                'adopted' => 0,
                'skipped' => 0,
                'conflicts' => 0,
                'failed' => 0,
                'planned' => 0,
            ],
            'issues' => [],
            'actions' => [],
            'nodes' => $nodes,
            'progress' => [
                'state' => 'running',
                'nodes' => $progressNodes,
            ],
        ];
    }

    /**
     * @return list<array<string, int|string>>
     */
    private function syntheticFleetProgressNodes(): array
    {
        $progressNodes = [];

        foreach ($this->fleetNodeOrder as $nodeName) {
            $phase = $this->fleetNodePhases[$nodeName] ?? 'queued';
            $progressNodes[] = [
                'node' => $nodeName,
                'status' => $phase,
            ];
        }

        return $progressNodes;
    }

    private function mode(): string|int
    {
        $flags = array_values(array_filter([
            (bool) $this->option('fix') ? 'fix' : null,
            (bool) $this->option('restore') ? 'restore' : null,
            (bool) $this->option('adopt') ? 'adopt' : null,
        ]));

        if (count($flags) > 1) {
            return $this->renderFailure(
                'validation_failed',
                '--fix, --restore, and --adopt are mutually exclusive.',
                ['fields' => $flags],
            );
        }

        return match ($flags[0] ?? null) {
            'fix' => 'interactive',
            'restore' => 'restore',
            'adopt' => 'adopt',
            default => 'verify',
        };
    }

    private function runInteractiveDoctor(): int
    {
        $frames = app(DoctorTerminalFrameExtractor::class);
        $selector = app(InteractiveDoctorIssueSelector::class);
        $payload = $this->payload('verify');

        if (is_int($payload)) {
            return $payload;
        }

        $probeFrame = $this->captureDoctorProgressTerminalFrame('/api/doctor/run', $payload);

        if (is_int($probeFrame)) {
            return $probeFrame;
        }

        $probe = $frames->doctor($probeFrame);

        if ($probe === null || $selector->issues($probe) === []) {
            return $this->renderDoctorPanel($probeFrame['type'], $probeFrame['payload'], 'interactive');
        }

        try {
            $selected = $selector->select(
                probe: $probe,
                ask: fn (string $question, array $choices, string $default): string => (string) $this->choice(
                    $question,
                    $choices,
                    $default,
                ),
                write: function (string $line): void {
                    $this->line($line);
                },
            );
        } catch (Throwable) {
            return $this->renderFailure(
                'validation_failed',
                'Operation cancelled.',
                ['field' => 'fix'],
            );
        }

        $finalFrame = null;

        foreach (['restore', 'adopt'] as $resolutionMode) {
            $issues = $selected[$resolutionMode];

            if ($issues === []) {
                continue;
            }

            $resolutionPayload = $this->payload($resolutionMode);

            if (is_int($resolutionPayload)) {
                return $resolutionPayload;
            }

            $fixFrame = $this->captureDoctorProgressTerminalFrame(
                '/api/doctor/fix',
                [
                    ...$resolutionPayload,
                    'issues' => $issues,
                ],
            );

            if (is_int($fixFrame)) {
                return $fixFrame;
            }

            if ($fixFrame['type'] === ProgressEventType::Error && $frames->doctor($fixFrame) === null) {
                return $this->renderProgressTerminalFrame($fixFrame['type'], $fixFrame['payload']);
            }

            $finalFrame = $fixFrame;
        }

        if ($finalFrame !== null) {
            return $this->renderDoctorPanel($finalFrame['type'], $finalFrame['payload'], 'interactive');
        }

        return $this->renderDoctorPanel($probeFrame['type'], $probeFrame['payload'], 'interactive');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function streamDoctorJson(string $path, array $payload): int
    {
        $client = app(GatewayStreamClient::class);
        $streamStarted = false;
        $idleWriter = app(StreamJsonIdleStepWriter::class);
        $stdout = $this->resolveStreamJsonStdoutStream();

        $emitFrame = function (ProgressEventType $type, array $eventPayload): void {
            $this->line(json_encode(
                $this->doctorStreamFrame($type, $eventPayload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        };

        $writeIdleLine = function (string $line): void {
            $this->output->write($line);
        };

        try {
            return $client->streamEvents(
                path: $path,
                payload: $payload,
                onEvent: function (ProgressEventType $type, array $eventPayload) use (
                    &$streamStarted,
                    $emitFrame,
                    $idleWriter,
                    $writeIdleLine,
                    $stdout,
                ): void {
                    $streamStarted = true;
                    $idleWriter->stop();

                    $runningStepPayload = $this->replayableRunningStepPayload($type, $eventPayload);

                    $emitFrame($type, $eventPayload);

                    if ($runningStepPayload !== null) {
                        $idleWriter->start(
                            json_encode(
                                $this->doctorStreamFrame(ProgressEventType::Step, $runningStepPayload),
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                            )
                                .PHP_EOL,
                            $writeIdleLine,
                            max(1, (int) ceil(self::STREAM_JSON_IDLE_PROGRESS_INTERVAL_MICROSECONDS / 1_000_000)),
                            $stdout,
                        );
                    }
                },
            );
        } catch (GatewayApiException $exception) {
            $idleWriter->stop();

            if ($streamStarted) {
                $this->line(json_encode(
                    $this->doctorStreamGatewayFailureFrame($exception),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ));

                return self::FAILURE;
            }

            return $this->renderGatewayFailure($exception);
        } finally {
            $idleWriter->stop();
        }
    }

    /**
     * @return resource|null
     */
    private function resolveStreamJsonStdoutStream(): mixed
    {
        $output = $this->output;

        if (method_exists($output, 'getOutput')) {
            $output = $output->getOutput();
        }

        if ($output instanceof StreamOutput) {
            return $output->getStream();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function replayableRunningStepPayload(ProgressEventType $type, array $payload): ?array
    {
        if ($type !== ProgressEventType::Step) {
            return null;
        }

        $status = $payload['status'] ?? null;

        if (! is_string($status) || ! in_array($status, ['running', 'progress', 'checking'], true)) {
            return null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function doctorStreamFrame(ProgressEventType $type, array $payload): array
    {
        $terminal = new ProgressEvent($type, $payload);

        if ($terminal->isSuccessfulTerminal()) {
            return [
                'event' => $type->value,
                'success' => $this->doctorStreamSuccess($type, $payload),
            ];
        }

        if ($type === ProgressEventType::Complete) {
            return [
                'event' => $type->value,
                'error' => $this->progressFailedCompleteError($terminal),
            ];
        }

        if ($type === ProgressEventType::Error) {
            return [
                'event' => $type->value,
                'error' => $this->doctorStreamError($type, $payload),
            ];
        }

        return [
            'event' => $type->value,
            'data' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function doctorStreamGatewayFailureFrame(GatewayApiException $exception): array
    {
        if ($exception->hasGatewayError()) {
            $envelope = JsonEnvelope::failure(
                $exception->gatewayErrorCode() ?? $exception->cliFailureCode(),
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                $exception->gatewayErrorMeta(),
            );

            if ($exception->gatewayErrorData() !== []) {
                $envelope['error']['data'] = $exception->gatewayErrorData();
            }

            return [
                'event' => ProgressEventType::Error->value,
                'error' => $envelope['error'],
            ];
        }

        return [
            'event' => ProgressEventType::Error->value,
            'error' => JsonEnvelope::failure(
                $exception->cliFailureCode(),
                $exception->getMessage(),
            )['error'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function doctorStreamSuccess(ProgressEventType $type, array $payload): array
    {
        $doctor = app(DoctorTerminalFrameExtractor::class)->doctor([
            'type' => $type,
            'payload' => $payload,
        ]);

        $data = $doctor === null
            ? $this->doctorStreamTerminalData($payload)
            : ['doctor' => $doctor];

        $envelope = JsonEnvelope::success($data, $this->doctorStreamSuccessMeta($payload));

        return $envelope['success'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function doctorStreamError(ProgressEventType $type, array $payload): array
    {
        $data = $this->doctorStreamTerminalData($payload);
        $code =
            $this->stringFromPayload($data, 'code') ?? $this->stringFromPayload($payload, 'code')
                ?? 'gateway_stream_error';
        $message =
            $this->stringFromPayload($data, 'message') ?? $this->stringFromPayload($payload, 'message')
                ?? 'Gateway progress stream failed.';
        $meta = $this->arrayFromPayload($data, 'meta') ?? $this->arrayFromPayload($payload, 'meta') ?? [];

        $envelope = JsonEnvelope::failure($code, $message, $meta);
        $error = $envelope['error'];
        $diagnosticData = $this->doctorStreamErrorData($type, $payload);

        if ($diagnosticData !== []) {
            $error['data'] = $diagnosticData;
        }

        return $error;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function doctorStreamTerminalData(array $payload): array
    {
        $data = $payload['data'] ?? null;

        return is_array($data) ? $data : $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function doctorStreamSuccessMeta(array $payload): array
    {
        $exitCode = $payload['exit_code'] ?? null;

        if (! is_int($exitCode)) {
            return [];
        }

        return ['exit_code' => $exitCode];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function doctorStreamErrorData(ProgressEventType $type, array $payload): array
    {
        $doctor = app(DoctorTerminalFrameExtractor::class)->doctor([
            'type' => $type,
            'payload' => $payload,
        ]);

        if ($doctor !== null) {
            return ['doctor' => $doctor];
        }

        $data = $this->doctorStreamTerminalData($payload);
        $nestedData = $data['data'] ?? null;

        return is_array($nestedData) ? $nestedData : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringFromPayload(array $payload, string $key): ?string
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
    private function arrayFromPayload(array $payload, string $key): ?array
    {
        $value = $payload[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>|int
     */
    private function payload(string $mode): array|int
    {
        try {
            $node = $this->doctorTargetNode();
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        }

        $self = (bool) $this->option('self') || $this->usesCallerNodeFallback($node);

        // Machine modes omit full intermediate doctor snapshots on the wire.
        // Human mode keeps full progress snapshots for the bordered panel.
        $machineMode = $this->wantsJson() || $this->wantsStreamJson();

        return $this->filledQuery([
            'mode' => $mode,
            'families' => $this->families(),
            'key' => $this->stringOption('key'),
            'node' => $node,
            'self' => $self ? true : null,
            'all' => (bool) $this->option('all') ? true : null,
            'instance' => $this->stringOption('instance'),
            'workspace' => $this->stringOption('workspace'),
            'dry_run' => (bool) $this->option('dry-run') ? true : null,
            'compact_progress' => $machineMode ? true : null,
        ]);
    }

    private function doctorTargetNode(): ?string
    {
        if ((bool) $this->option('all') || (bool) $this->option('self')) {
            return null;
        }

        if (
            $this->stringOption('node') === null
            && ($this->stringOption('instance') !== null
            || $this->stringOption('workspace') !== null)
        ) {
            return null;
        }

        return $this->targetNodeOptionOrDefault();
    }

    private function usesCallerNodeFallback(?string $node): bool
    {
        if ($node !== null) {
            return false;
        }

        if ((bool) $this->option('all') || (bool) $this->option('self')) {
            return false;
        }

        if ($this->stringOption('instance') !== null || $this->stringOption('workspace') !== null) {
            return false;
        }

        return $this->stringOption('node') === null;
    }

    private function validateDoctorScopeOptions(string $mode): ?int
    {
        $node = $this->stringOption('node');

        if ($node !== null && strtolower($node) === 'all') {
            return $this->renderFailure(
                'validation_failed',
                'Use --all to run doctor across the fleet; --node=all is not supported.',
                ['field' => 'node', 'value' => 'all'],
            );
        }

        if (! (bool) $this->option('all')) {
            return null;
        }

        if ($mode !== 'verify') {
            return $this->renderFailure(
                'validation_failed',
                'Fleet doctor runs are verify-only; resolution modes require a single target node.',
                ['field' => 'all'],
            );
        }

        $conflicts = array_values(array_filter([
            $node !== null ? 'node' : null,
            (bool) $this->option('self') ? 'self' : null,
            $this->stringOption('instance') !== null ? 'instance' : null,
            $this->stringOption('workspace') !== null ? 'workspace' : null,
        ]));

        if ($conflicts === []) {
            return null;
        }

        return $this->renderFailure(
            'validation_failed',
            '--all cannot be combined with node, self, instance, or workspace scope.',
            ['fields' => ['all', ...$conflicts]],
        );
    }

    /**
     * @return list<string>
     */
    private function families(): array
    {
        $families = $this->option('family');

        if (! is_array($families)) {
            return [];
        }

        return array_values(array_filter($families, fn (mixed $family): bool => is_string($family) && $family !== ''));
    }

    #[\Override]
    protected function wantsJson(): bool
    {
        return (bool) $this->option('json') || $this->wantsStreamJson();
    }

    private function wantsStreamJson(): bool
    {
        return (bool) $this->option('stream-json');
    }
}
