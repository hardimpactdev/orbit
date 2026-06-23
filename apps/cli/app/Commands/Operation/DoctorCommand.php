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
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Progress\ProgressEventType;
use Throwable;

final class DoctorCommand extends GatewayCommand
{
    use ResolvesHostContext;
    use StreamsGatewayProgress;

    #[\Override]
    protected $signature = 'doctor
        {--app= : Limit to one app}
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
                fn (ProgressEventType $type, array $payload): int => $this->renderProgressTerminalFrame($type, $payload),
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

        if ($this->isFleetReport($report)) {
            $this->writeDoctorFleetResult($report);

            return $type === ProgressEventType::Complete ? self::SUCCESS : self::FAILURE;
        }

        $this->writeDoctorPanel($report);

        return $type === ProgressEventType::Complete ? self::SUCCESS : self::FAILURE;
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
        $wantsJson = $this->wantsJson();
        $renderedDoctorFrame = false;

        $finalType = null;
        $finalPayload = [];

        try {
            $client->streamEvents(
                path: $path,
                payload: $payload,
                onEvent: function (ProgressEventType $type, array $eventPayload) use ($wantsJson, $frames, &$renderedDoctorFrame, &$finalType, &$finalPayload): void {
                    if ($type === ProgressEventType::Complete || $type === ProgressEventType::Error) {
                        $finalType = $type;
                        $finalPayload = $eventPayload;

                        return;
                    }

                    if ($wantsJson) {
                        return;
                    }

                    $doctor = $frames->doctor([
                        'type' => $type,
                        'payload' => $eventPayload,
                    ]);

                    if ($doctor !== null) {
                        $renderedDoctorFrame = true;
                        $this->writeDoctorProgressPanel($doctor);

                        return;
                    }

                    if (! $renderedDoctorFrame) {
                        $this->renderProgressFrame($type, $eventPayload);
                    }
                },
            );
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($finalType instanceof ProgressEventType) {
            if (! $wantsJson && $this->progressTree?->isStarted()) {
                $data = $this->frameData($finalPayload);
                $footer = $this->frameString($data, 'footer') ?? $this->frameString($finalPayload, 'footer');

                $this->progressTree->finish(
                    $footer ?? ($finalType === ProgressEventType::Complete ? 'Done' : 'Failed'),
                    success: $finalType === ProgressEventType::Complete,
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
     * @param  array<string, mixed>  $report
     */
    private function writeDoctorPanel(array $report): void
    {
        $lines = app(DoctorPanelRenderer::class)->lines($report);

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
     * @param  array<string, mixed>  $report
     */
    private function writeDoctorFleetResult(array $report): void
    {
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $issues = is_int($summary['issues'] ?? null) ? $summary['issues'] : 0;
        $rawNodes = $report['nodes'] ?? [];
        $nodes = is_array($rawNodes) ? array_values(array_filter($rawNodes, is_array(...))) : [];
        $targetCount = count($nodes);

        $this->line('F L E E T  D O C T O R  R E S U L T');
        $this->line($targetCount === 1 ? 'Checked 1 node' : "Checked {$targetCount} nodes");

        foreach ($nodes as $node) {
            $name = is_string($node['node'] ?? null) ? $node['node'] : 'unknown';
            $role = is_string($node['role'] ?? null) ? $node['role'] : 'unknown';
            $healthy = ($node['healthy'] ?? false) === true ? 'OK' : 'ISSUES';
            $nodeSummary = is_array($node['summary'] ?? null) ? $node['summary'] : [];
            $nodeIssues = is_int($nodeSummary['issues'] ?? null) ? $nodeSummary['issues'] : 0;

            $this->line("{$name}  {$role}  {$healthy}  {$nodeIssues} issues");
        }

        $this->line($issues === 0 ? 'No issues detected' : ($issues === 1 ? '1 issue detected' : "{$issues} issues detected"));
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
                ask: fn (string $question, array $choices, string $default): string => (string) $this->choice($question, $choices, $default),
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

        try {
            return $client->streamEvents(
                path: $path,
                payload: $payload,
                onEvent: function (ProgressEventType $type, array $eventPayload) use (&$streamStarted): void {
                    $streamStarted = true;

                    $this->line(json_encode(
                        $this->doctorStreamFrame($type, $eventPayload),
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                    ));
                },
            );
        } catch (GatewayApiException $exception) {
            if ($streamStarted) {
                $this->line(json_encode(
                    $this->doctorStreamGatewayFailureFrame($exception),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ));

                return self::FAILURE;
            }

            return $this->renderGatewayFailure($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function doctorStreamFrame(ProgressEventType $type, array $payload): array
    {
        if ($type === ProgressEventType::Complete) {
            return [
                'event' => $type->value,
                'success' => $this->doctorStreamSuccess($type, $payload),
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
        $code = $this->stringFromPayload($data, 'code')
            ?? $this->stringFromPayload($payload, 'code')
            ?? 'gateway_stream_error';
        $message = $this->stringFromPayload($data, 'message')
            ?? $this->stringFromPayload($payload, 'message')
            ?? 'Gateway progress stream failed.';
        $meta = $this->arrayFromPayload($data, 'meta')
            ?? $this->arrayFromPayload($payload, 'meta')
            ?? [];

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

        return $this->filledQuery([
            'mode' => $mode,
            'families' => $this->families(),
            'key' => $this->stringOption('key'),
            'node' => $node,
            'self' => $self ? true : null,
            'all' => (bool) $this->option('all') ? true : null,
            'app' => $this->stringOption('app'),
            'workspace' => $this->stringOption('workspace'),
            'dry_run' => (bool) $this->option('dry-run') ? true : null,
        ]);
    }

    private function doctorTargetNode(): ?string
    {
        if ((bool) $this->option('all') || (bool) $this->option('self')) {
            return null;
        }

        if (
            $this->stringOption('node') === null
            && ($this->stringOption('app') !== null || $this->stringOption('workspace') !== null)
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

        if ($this->stringOption('app') !== null || $this->stringOption('workspace') !== null) {
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
            $this->stringOption('app') !== null ? 'app' : null,
            $this->stringOption('workspace') !== null ? 'workspace' : null,
        ]));

        if ($conflicts === []) {
            return null;
        }

        return $this->renderFailure(
            'validation_failed',
            '--all cannot be combined with node, self, app, or workspace scope.',
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
