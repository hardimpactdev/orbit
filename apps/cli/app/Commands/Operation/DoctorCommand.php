<?php

declare(strict_types=1);

namespace App\Commands\Operation;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\Concerns\StreamsGatewayProgress;
use App\Commands\GatewayCommand;
use App\Services\Doctor\DoctorPanelRenderer;
use App\Services\Doctor\DoctorTerminalFrameExtractor;
use App\Services\Doctor\InteractiveDoctorIssueSelector;
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
        {--family=* : Scope to one or more state families}
        {--key= : Limit reported drift to one exact doctor issue key}
        {--fix : Enter resolution mode}
        {--restore : Bulk restore gateway configuration to nodes}
        {--adopt : Bulk adopt node reality into gateway configuration}
        {--dry-run : Preview bulk restore or adopt actions without applying changes}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Check Orbit health and diagnose drift through the gateway.';

    public function handle(): int
    {
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

        if ($mode === 'interactive') {
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

        if ($this->wantsJson()) {
            return $this->streamProgress(
                $path,
                $this->payload($mode),
                fn (ProgressEventType $type, array $payload): int => $this->renderProgressTerminalFrame($type, $payload),
            );
        }

        $frame = $this->captureProgressTerminalFrame($path, $this->payload($mode));

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

        foreach (app(DoctorPanelRenderer::class)->lines($report) as $line) {
            $this->line($line);
        }

        return $type === ProgressEventType::Complete ? self::SUCCESS : self::FAILURE;
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
        $probeFrame = $this->captureProgressTerminalFrame('/api/doctor/run', $this->payload('verify'));

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

            $fixFrame = $this->captureProgressTerminalFrame(
                '/api/doctor/fix',
                [
                    ...$this->payload($resolutionMode),
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
     * @return array<string, mixed>
     */
    private function payload(string $mode): array
    {
        return $this->filledQuery([
            'mode' => $mode,
            'families' => $this->families(),
            'key' => $this->stringOption('key'),
            'node' => $this->stringOption('node'),
            'self' => (bool) $this->option('self') ? true : null,
            'app' => $this->stringOption('app'),
            'workspace' => $this->stringOption('workspace'),
            'dry_run' => (bool) $this->option('dry-run') ? true : null,
        ]);
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
}
