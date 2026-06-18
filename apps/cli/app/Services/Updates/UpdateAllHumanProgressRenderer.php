<?php

declare(strict_types=1);

namespace App\Services\Updates;

use Orbit\Core\Progress\ProgressEventType;
use Symfony\Component\Console\Output\OutputInterface;

final class UpdateAllHumanProgressRenderer
{
    private const string STATE_WAITING = 'waiting';

    private const string STATE_ACTIVE = 'active';

    private const string STATE_DONE = 'done';

    private const string STATE_FAILED = 'failed';

    private const string STATE_SKIPPED = 'skipped';

    private const string ROW_CHECK_UPDATES = 'check-updates';

    private const string ROW_CHECK_FLEET = 'check-fleet-versions';

    private const string STAGE_WAITING = 'waiting';

    private const string STAGE_CHECKING = 'checking';

    private const string STAGE_SETTLED = 'settled';

    private const string STAGE_SKIPPED = 'skipped';

    private const string STAGE_UPDATING_CLI = 'updating_cli';

    private const string STAGE_STARTING_OPERATION = 'starting_operation';

    private const string STAGE_ACQUIRING_LEASES = 'acquiring_leases';

    private const string STAGE_UPDATING_GATEWAY_SERVICE = 'updating_gateway_service';

    private const string STAGE_STOPPING_SCHEDULER = 'stopping_scheduler';

    private const string STAGE_RUNNING_GATEWAY_MIGRATIONS = 'running_gateway_migrations';

    private const string STAGE_STARTING_SCHEDULER = 'starting_scheduler';

    private const string STAGE_UPDATING_NODE_CLI = 'updating_node_cli';

    private const string STAGE_DOWNLOADING = 'downloading';

    private const string STAGE_REPLACING_CLI_BINARY = 'replacing_cli_binary';

    private const string STAGE_RUNNING_DOCTOR = 'running_doctor';

    private const string STAGE_PULLING_REQUIRED_IMAGES = 'pulling_required_images';

    private const string STAGE_VERIFYING = 'verifying';

    private const string STAGE_DONE = 'done';

    private const string STAGE_FAILED = 'failed';

    private const string DIM = "\e[38;5;242m";

    private const string ACCENT = "\e[97m";

    private const string GREEN = "\e[32m";

    private const string RED = "\e[31m";

    private const string ORANGE = "\e[38;5;208m";

    private const string RESET = "\e[39m";

    private const array SPINNER_FRAMES = [
        "\e[36m○\e[39m",
        "\e[36m◉\e[39m",
    ];

    /**
     * @var list<string>
     */
    private array $order = [];

    /**
     * @var array<string, array{state: string, stage: string, message: string}>
     */
    private array $rows = [];

    private int $targetWidth = 0;

    private int $frame = 0;

    private bool $rendered = false;

    private bool $finished = false;

    public function begin(OutputInterface $output): void
    {
        $this->renderInitial($output);
    }

    public function gatewayStarting(OutputInterface $output): void
    {
        $this->ensureTarget($output, 'gateway');
        $this->setRow($output, 'gateway', self::STATE_ACTIVE, self::STAGE_STARTING_OPERATION);
    }

    public function gatewayFailed(OutputInterface $output, string $message = ''): void
    {
        $this->ensureTarget($output, 'gateway');
        $this->setRow($output, 'gateway', self::STATE_FAILED, self::STAGE_FAILED, $message);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function applyEvent(OutputInterface $output, ProgressEventType $type, array $payload): void
    {
        if ($type === ProgressEventType::Tree) {
            return;
        }

        if ($type !== ProgressEventType::Step) {
            return;
        }

        $key = $this->frameString($payload, 'key');
        $status = $this->frameString($payload, 'status');
        $message = $this->frameString($payload, 'message');

        if ($key !== null && $this->applyKeyedStep($output, $key, $status, $message)) {
            return;
        }

        $resolved = $message
            ?? $status
            ?? $key;

        if ($resolved === null) {
            return;
        }

        $this->applyStepMessage($output, $resolved);
    }

    /**
     * Route the structured check-step and per-node rows by their journal key,
     * which is more robust than message matching (the two check steps share the
     * `Checking` in-progress message and only differ by key). Returns true when
     * the key was handled.
     */
    private function applyKeyedStep(OutputInterface $output, string $key, ?string $status, ?string $message): bool
    {
        if ($key === self::ROW_CHECK_UPDATES || $key === self::ROW_CHECK_FLEET) {
            $this->ensureTarget($output, $key);

            if ($status === 'done') {
                $this->setRow($output, $key, self::STATE_DONE, self::STAGE_SETTLED, $message ?? '');

                return true;
            }

            if ($status === 'fail') {
                $this->setRow($output, $key, self::STATE_FAILED, self::STAGE_SETTLED, $message ?? 'Failed');

                return true;
            }

            $this->setRow($output, $key, self::STATE_ACTIVE, self::STAGE_CHECKING);

            return true;
        }

        if (str_starts_with($key, 'workload.')) {
            $node = $this->normalizeTarget(substr($key, strlen('workload.')));
            $this->ensureTarget($output, $node);

            if ($status === 'done') {
                if ($message !== null && str_contains($message, 'skipped: already up to date')) {
                    $this->setRow($output, $node, self::STATE_SKIPPED, self::STAGE_SKIPPED);

                    return true;
                }

                $this->setRow($output, $node, self::STATE_DONE, self::STAGE_DONE, $this->nodeDoctorSuffix($message));

                return true;
            }

            if ($status === 'fail') {
                $this->setRow($output, $node, self::STATE_FAILED, self::STAGE_FAILED, $message ?? '');

                return true;
            }

            // running: check for known per-node sub-stage messages
            if ($status === 'running' && $message !== null) {
                [$stage, $stageMessage] = $this->subStageFromMessage($message);
                $this->setRow($output, $node, self::STATE_ACTIVE, $stage, $stageMessage);

                return true;
            }

            $this->setRow($output, $node, self::STATE_ACTIVE, self::STAGE_UPDATING_NODE_CLI);

            return true;
        }

        return false;
    }

    /**
     * Map a gateway-emitted sub-stage message to a [stage, row-message] pair.
     * Returns the renderer stage constant and the text to store in the row
     * message field. Unknown messages fall back to `STAGE_UPDATING_NODE_CLI`.
     *
     * @return array{0: string, 1: string}
     */
    private function subStageFromMessage(string $message): array
    {
        if (str_starts_with($message, 'Downloading ')) {
            // Extract version/suffix: "Downloading 1.2.3" → stage "Downloading" + message "1.2.3"
            $suffix = substr($message, strlen('Downloading '));

            return [self::STAGE_DOWNLOADING, $suffix];
        }

        return match ($message) {
            'Replacing cli binary' => [self::STAGE_REPLACING_CLI_BINARY, ''],
            'Running doctor' => [self::STAGE_RUNNING_DOCTOR, ''],
            default => [self::STAGE_UPDATING_NODE_CLI, ''],
        };
    }

    /**
     * Extract a `(<n> issues)` suffix from a `Workload node <name> updated
     * (<n> issues)` done message so the node row settles to `Done (n issues)`.
     */
    private function nodeDoctorSuffix(?string $message): string
    {
        if ($message !== null && preg_match('/\((?P<count>\d+ issues?)\)$/', $message, $matches) === 1) {
            return "({$matches['count']})";
        }

        return '';
    }

    /**
     * Advance the local node fan-out row through a sub-stage message.
     * Registers the `local` row on first call (when the gateway phase succeeds
     * and local fan-out begins).
     */
    public function localNodeSubStep(OutputInterface $output, string $stage, string $message = ''): void
    {
        $this->ensureTarget($output, 'local');
        $this->setRow($output, 'local', self::STATE_ACTIVE, $stage, $message);
    }

    public function localNodeSucceeded(OutputInterface $output, ?int $issues = null): void
    {
        $this->ensureTarget($output, 'local');

        $suffix = ($issues !== null && $issues > 0)
            ? '('.$issues.' '.($issues === 1 ? 'issue' : 'issues').')'
            : '';

        $this->setRow($output, 'local', self::STATE_DONE, self::STAGE_DONE, $suffix);
    }

    public function localNodeFailed(OutputInterface $output, string $message = ''): void
    {
        $this->ensureTarget($output, 'local');
        $this->setRow($output, 'local', self::STATE_FAILED, self::STAGE_FAILED, $message);
    }

    public function localNodeSkipped(OutputInterface $output): void
    {
        $this->ensureTarget($output, 'local');
        $this->setRow($output, 'local', self::STATE_SKIPPED, self::STAGE_SKIPPED);
    }

    public function finishSuccess(OutputInterface $output, ?string $targetVersion = null, bool $allCurrent = false): void
    {
        foreach ($this->order as $target) {
            $state = $this->rows[$target]['state'] ?? self::STATE_WAITING;

            // Preserve settled check rows, skipped (orange) rows, and any failed
            // row; only still-pending node/gateway rows settle to Done.
            if (in_array($state, [self::STATE_DONE, self::STATE_FAILED, self::STATE_SKIPPED], true)
                || in_array($this->rows[$target]['stage'] ?? '', [self::STAGE_SETTLED, self::STAGE_CHECKING], true)) {
                continue;
            }

            $this->setRow($output, $target, self::STATE_DONE, self::STAGE_DONE);
        }

        $this->finish($output, $this->successFooter($targetVersion, $allCurrent), success: true);
    }

    private function successFooter(?string $targetVersion, bool $allCurrent = false): string
    {
        if ($allCurrent && $targetVersion !== null && $targetVersion !== '') {
            return "Skipped: {$targetVersion} is already installed on all nodes";
        }

        if ($targetVersion === null || $targetVersion === '') {
            return 'Success';
        }

        return "Success: All nodes are running on version {$targetVersion}";
    }

    public function finishFailure(OutputInterface $output): void
    {
        $this->finish($output, 'Failed', success: false);
    }

    private function applyStepMessage(OutputInterface $output, string $message): void
    {
        if (preg_match('/^Updating workload node (?P<node>.+)$/', $message, $matches) === 1) {
            $node = $this->normalizeTarget($matches['node']);

            $this->ensureTarget($output, $node);
            $this->setRow($output, $node, self::STATE_ACTIVE, self::STAGE_UPDATING_NODE_CLI);

            return;
        }

        if (preg_match('/^Workload node (?P<node>.+) updated$/', $message, $matches) === 1) {
            $node = $this->normalizeTarget($matches['node']);

            $this->ensureTarget($output, $node);
            $this->setRow($output, $node, self::STATE_DONE, self::STAGE_DONE);

            return;
        }

        match ($message) {
            'Update plan persisted',
            'Update runner started' => $this->setGatewayStage($output, self::STAGE_STARTING_OPERATION),
            'Fleet update lease acquired',
            'Gateway and scheduler update leases acquired' => $this->setGatewayStage($output, self::STAGE_ACQUIRING_LEASES),
            'Updating gateway services',
            'Updating gateway service',
            'Updating orbit-gateway service',
            'orbit-gateway service healthy' => $this->setGatewayStage($output, self::STAGE_UPDATING_GATEWAY_SERVICE),
            'Stopping orbit-scheduler service',
            'orbit-scheduler service stopped' => $this->setGatewayStage($output, self::STAGE_STOPPING_SCHEDULER),
            'Running gateway migrations',
            'Gateway migrations completed' => $this->setGatewayStage($output, self::STAGE_RUNNING_GATEWAY_MIGRATIONS),
            'Starting orbit-scheduler service',
            'orbit-scheduler service running' => $this->setGatewayStage($output, self::STAGE_STARTING_SCHEDULER),
            'Gateway services updated',
            'Fleet update verified' => $this->setRow($output, 'gateway', self::STATE_DONE, self::STAGE_DONE),
            'Pulling required images' => $this->setGatewayStage($output, self::STAGE_PULLING_REQUIRED_IMAGES),
            'Verifying fleet update',
            'Verifying orbit-gateway service',
            'Verifying orbit-scheduler service',
            'Verifying workload CLI artifacts',
            'Verifying required role images' => $this->setGatewayStage($output, self::STAGE_VERIFYING),
            default => null,
        };
    }

    private function setGatewayStage(OutputInterface $output, string $stage): void
    {
        $this->ensureTarget($output, 'gateway');
        $this->setRow($output, 'gateway', self::STATE_ACTIVE, $stage);
    }

    private function ensureTarget(OutputInterface $output, string $target): void
    {
        if (isset($this->rows[$target])) {
            return;
        }

        $previousWidth = $this->targetWidth;

        $this->registerTarget($target);

        if (! $this->rendered) {
            return;
        }

        $this->renderExtension($output, $target, $this->targetWidth !== $previousWidth);
    }

    private function registerTarget(string $target): void
    {
        if (isset($this->rows[$target])) {
            return;
        }

        $this->order[] = $target;
        $this->rows[$target] = [
            'state' => self::STATE_WAITING,
            'stage' => self::STAGE_WAITING,
            'message' => '',
        ];
        $this->targetWidth = max($this->targetWidth, strlen($this->displayName($target)));
    }

    private function displayName(string $target): string
    {
        return match ($target) {
            self::ROW_CHECK_UPDATES => 'Checking for updates',
            self::ROW_CHECK_FLEET => 'Checking fleet versions',
            default => $target,
        };
    }

    private function renderInitial(OutputInterface $output): void
    {
        $output->writeln('┌ Updating Orbit');

        foreach ($this->order as $target) {
            $output->writeln($this->rowLine($target, $output->isDecorated()));
        }

        $output->writeln($this->footerLine('Working...', success: null, styled: $output->isDecorated()));

        if ($output->isDecorated()) {
            $output->write("\e[?25l");
        }

        $this->rendered = true;
    }

    private function renderExtension(OutputInterface $output, string $target, bool $widthChanged): void
    {
        if (! $output->isDecorated()) {
            $output->writeln($this->rowLine($target, styled: false));

            return;
        }

        $output->write("\e[1A\e[2K\r");
        $output->writeln($this->rowLine($target, styled: true));
        $output->writeln($this->footerLine('Working...', success: null, styled: true));

        if (! $widthChanged) {
            return;
        }

        foreach ($this->order as $existingTarget) {
            if ($existingTarget === $target) {
                continue;
            }

            $this->repaintRow($output, $existingTarget);
        }
    }

    private function setRow(OutputInterface $output, string $target, string $state, string $stage, string $message = ''): void
    {
        if (! isset($this->rows[$target])) {
            return;
        }

        $this->rows[$target] = [
            'state' => $state,
            'stage' => $stage,
            'message' => $message,
        ];

        $this->repaintRow($output, $target);
    }

    private function repaintRow(OutputInterface $output, string $target): void
    {
        if (! $this->rendered) {
            return;
        }

        $index = array_search($target, $this->order, true);

        if ($index === false) {
            return;
        }

        $line = $this->rowLine($target, $output->isDecorated());

        if (! $output->isDecorated()) {
            $output->writeln($line);

            return;
        }

        $up = count($this->order) - $index + 1;
        $output->write("\e[{$up}A\e[2K\r{$line}\e[{$up}B\r");
    }

    private function finish(OutputInterface $output, string $footer, bool $success): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;

        $line = $this->footerLine($footer, $success, $output->isDecorated());

        if (! $output->isDecorated()) {
            $output->writeln($line);

            return;
        }

        $output->write("\e[1A\e[2K\r{$line}\e[1B\r\e[?25h");
        $output->writeln('');
    }

    private function rowLine(string $target, bool $styled): string
    {
        $row = $this->rows[$target];
        $targetName = str_pad($this->displayName($target), $this->targetWidth);
        $stage = $this->stageName($row['stage']);
        $label = $stage === '' ? $targetName : "{$targetName} {$stage}";

        $line = match ($row['state']) {
            self::STATE_ACTIVE => $this->activeIcon($styled).' '.$this->decorate($label, self::ACCENT, $styled),
            self::STATE_DONE => $this->decorate('●', self::GREEN, $styled).' '.$this->decorate($label, self::ACCENT, $styled),
            self::STATE_SKIPPED => $this->decorate('●', self::ORANGE, $styled).' '.$this->decorate($label, self::ACCENT, $styled),
            self::STATE_FAILED => $this->decorate('●', self::RED, $styled).' '.$this->decorate($label, self::RED, $styled),
            default => $this->decorate('○ '.$label, self::DIM, $styled),
        };

        if ($row['message'] !== '') {
            $line .= ' '.$this->decorate($row['message'], $row['state'] === self::STATE_FAILED ? self::RED : self::DIM, $styled);
        }

        return $styled ? $line : $this->stripAnsi($line);
    }

    private function footerLine(string $footer, ?bool $success, bool $styled): string
    {
        $color = match ($success) {
            true => self::ACCENT,
            false => self::RED,
            null => self::DIM,
        };

        return $this->stripAnsiIfNeeded('└ '.$this->decorate($footer, $color, $styled), $styled);
    }

    private function activeIcon(bool $styled): string
    {
        if (! $styled) {
            return $this->frame++ % 2 === 0 ? '○' : '◉';
        }

        return self::SPINNER_FRAMES[$this->frame++ % count(self::SPINNER_FRAMES)];
    }

    private function stageName(string $stage): string
    {
        return match ($stage) {
            self::STAGE_WAITING => 'Waiting',
            self::STAGE_CHECKING => 'Checking',
            self::STAGE_SETTLED => '',
            self::STAGE_SKIPPED => 'Skipped: already up to date',
            self::STAGE_UPDATING_CLI => 'Updating CLI',
            self::STAGE_STARTING_OPERATION => 'Starting operation',
            self::STAGE_ACQUIRING_LEASES => 'Acquiring leases',
            self::STAGE_UPDATING_GATEWAY_SERVICE => 'Updating gateway service',
            self::STAGE_STOPPING_SCHEDULER => 'Stopping scheduler',
            self::STAGE_RUNNING_GATEWAY_MIGRATIONS => 'Running gateway migrations',
            self::STAGE_STARTING_SCHEDULER => 'Starting scheduler',
            self::STAGE_UPDATING_NODE_CLI => 'Updating node CLI',
            self::STAGE_DOWNLOADING => 'Downloading',
            self::STAGE_REPLACING_CLI_BINARY => 'Replacing cli binary',
            self::STAGE_RUNNING_DOCTOR => 'Running doctor',
            self::STAGE_PULLING_REQUIRED_IMAGES => 'Pulling required images',
            self::STAGE_VERIFYING => 'Verifying',
            self::STAGE_DONE => 'Done',
            self::STAGE_FAILED => 'Failed',
            default => $stage,
        };
    }

    private function normalizeTarget(string $target): string
    {
        $target = trim($target);

        return $target === '' ? 'unknown' : $target;
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

    private function decorate(string $text, string $color, bool $styled): string
    {
        return $styled ? $color.$text.self::RESET : $text;
    }

    private function stripAnsiIfNeeded(string $text, bool $styled): string
    {
        return $styled ? $text : $this->stripAnsi($text);
    }

    private function stripAnsi(string $text): string
    {
        return preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $text) ?? $text;
    }
}
