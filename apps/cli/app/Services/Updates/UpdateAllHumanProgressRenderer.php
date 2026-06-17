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

    private const string STAGE_WAITING = 'waiting';

    private const string STAGE_UPDATING_CLI = 'updating_cli';

    private const string STAGE_STARTING_OPERATION = 'starting_operation';

    private const string STAGE_ACQUIRING_LEASES = 'acquiring_leases';

    private const string STAGE_UPDATING_GATEWAY_SERVICE = 'updating_gateway_service';

    private const string STAGE_STOPPING_SCHEDULER = 'stopping_scheduler';

    private const string STAGE_RUNNING_GATEWAY_MIGRATIONS = 'running_gateway_migrations';

    private const string STAGE_STARTING_SCHEDULER = 'starting_scheduler';

    private const string STAGE_UPDATING_NODE_CLI = 'updating_node_cli';

    private const string STAGE_PULLING_REQUIRED_IMAGES = 'pulling_required_images';

    private const string STAGE_VERIFYING = 'verifying';

    private const string STAGE_DONE = 'done';

    private const string STAGE_FAILED = 'failed';

    private const string DIM = "\e[38;5;242m";

    private const string ACCENT = "\e[97m";

    private const string GREEN = "\e[32m";

    private const string RED = "\e[31m";

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
        $this->registerTarget('local');
        $this->renderInitial($output);
        $this->setRow($output, 'local', self::STATE_ACTIVE, self::STAGE_UPDATING_CLI);
    }

    public function localSucceeded(OutputInterface $output): void
    {
        $this->setRow($output, 'local', self::STATE_DONE, self::STAGE_DONE);
    }

    public function localFailed(OutputInterface $output, string $message = ''): void
    {
        $this->setRow($output, 'local', self::STATE_FAILED, self::STAGE_FAILED, $message);
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

        $message = $this->frameString($payload, 'message')
            ?? $this->frameString($payload, 'status')
            ?? $this->frameString($payload, 'key');

        if ($message === null) {
            return;
        }

        $this->applyStepMessage($output, $message);
    }

    public function finishSuccess(OutputInterface $output): void
    {
        foreach ($this->order as $target) {
            if (($this->rows[$target]['state'] ?? self::STATE_WAITING) !== self::STATE_FAILED) {
                $this->setRow($output, $target, self::STATE_DONE, self::STAGE_DONE);
            }
        }

        $count = count($this->order);
        $noun = $count === 1 ? 'node' : 'nodes';

        $this->finish($output, "Successfully updated {$count} {$noun}", success: true);
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
        $this->targetWidth = max($this->targetWidth, strlen($target));
    }

    private function renderInitial(OutputInterface $output): void
    {
        $output->writeln('┌ Updating Orbit nodes');

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
        $targetName = str_pad($target, $this->targetWidth);
        $label = "{$targetName} ".$this->stageName($row['stage']);

        $line = match ($row['state']) {
            self::STATE_ACTIVE => $this->activeIcon($styled).' '.$this->decorate($label, self::ACCENT, $styled),
            self::STATE_DONE => $this->decorate('●', self::GREEN, $styled).' '.$this->decorate($label, self::ACCENT, $styled),
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
            self::STAGE_UPDATING_CLI => 'Updating CLI',
            self::STAGE_STARTING_OPERATION => 'Starting operation',
            self::STAGE_ACQUIRING_LEASES => 'Acquiring leases',
            self::STAGE_UPDATING_GATEWAY_SERVICE => 'Updating gateway service',
            self::STAGE_STOPPING_SCHEDULER => 'Stopping scheduler',
            self::STAGE_RUNNING_GATEWAY_MIGRATIONS => 'Running gateway migrations',
            self::STAGE_STARTING_SCHEDULER => 'Starting scheduler',
            self::STAGE_UPDATING_NODE_CLI => 'Updating node CLI',
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
