<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Commands\GatewayCommand;
use App\Data\GatewayProfile;
use App\Support\Console\Animation;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\InterruptIntent;
use App\Support\Console\ProgressDisplay;
use App\Support\Console\ProgressState;
use App\Support\Console\TerminalText;
use App\Support\Operations\OperationFollower;
use App\Support\Operations\OperationOutputLine;
use App\Support\Operations\OperationOutputTail;
use App\Support\Operations\OperationStepLabels;
use App\Support\Realtime\AuthorizedRealtimeChannel;
use App\Support\Realtime\GatewayChannelAuthorizer;
use App\Support\Realtime\RealtimeConnectionConfig;
use App\Support\Realtime\RealtimeSubscriber;
use App\Support\Realtime\WebSocketTransport;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Responses\Operations\OperationStepResponse;
use Throwable;

/**
 * Follows an operation that a command started until it ends (docs/reference/operations.md): over the operation's
 * Reverb channel when it can, by polling `operation:show` when it cannot. Ctrl-C stops following, not the operation.
 * Human output shows the steps as a progress tree, with new output of each step above it: live while the Node agent
 * publishes it, otherwise from each read. `--json` prints only the final operation.
 *
 * @phpstan-require-extends GatewayCommand
 */
trait FollowsOperations
{
    /** @var array<string, OperationStepResponse> */
    private array $operationSteps = [];

    /** @var array<string, true> */
    private array $settledSteps = [];

    private ?OperationOutputTail $outputTail = null;

    protected function followOperation(GatewayConnector $connector, GatewayProfile $profile, OperationResponse $started, string $title, string $verb): int
    {
        $follower = new OperationFollower(
            fn (int $afterSequence): OperationResponse => $this->sendOrThrow($connector, new ShowOperationRequest($started->id, $afterSequence), OperationResponse::class),
            $this->operationSubscriber($connector, $profile, $started->id),
            $this->option('json') === true ? null : function (OperationOutputLine $output): void {
                $this->outputTail?->printedLive($output->step);
                $this->writeOperationOutputLine($output->step, $output->line);
            },
        );
        $handlers = [];
        $async = null;

        try {
            if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal_get_handler')) {
                $async = pcntl_async_signals();

                foreach ([SIGINT, SIGTERM] as $signal) {
                    $handlers[$signal] = pcntl_signal_get_handler($signal);
                    pcntl_signal($signal, static function (int $received): never {
                        throw new ConsoleInterrupted($received);
                    }, restart_syscalls: false);
                }

                pcntl_async_signals(true);
            }

            return $this->option('json') === true
                ? $this->followOperationMachine($connector, $follower, $started)
                : $this->followOperationHuman($follower, $started, $title, $verb);
        } catch (GatewayApiException $exception) {
            if (InterruptIntent::pending() === null) {
                return $this->renderApiFailure($exception);
            }
        } catch (Throwable $exception) {
            if (! InterruptIntent::cancellation($exception)) {
                throw $exception;
            }
        } finally {
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }

            if ($async !== null) {
                pcntl_async_signals($async);
            }

            $follower->close();
        }

        // Ctrl-C stops following; GatewayCommand turns the pending signal into exit status 130.
        if ($this->option('json') !== true) {
            $this->writeHumanMessage("The operation keeps running. Follow it with: orbit operation:show {$started->id}");
        }

        return self::FAILURE;
    }

    private function followOperationMachine(GatewayConnector $connector, OperationFollower $follower, OperationResponse $operation): int
    {
        while (! $operation->terminal()) {
            $operation = $follower->next($operation);
        }

        $final = $this->sendOrThrow($connector, new ShowOperationRequest($operation->id), OperationResponse::class);
        $this->writeJson($final->toArray());

        return $final->succeeded() ? self::SUCCESS : self::FAILURE;
    }

    private function followOperationHuman(OperationFollower $follower, OperationResponse $operation, string $title, string $verb): int
    {
        $this->operationSteps = [];
        $this->settledSteps = [];
        $this->outputTail = new OperationOutputTail;
        $progress = $this->progressDisplay($title);
        $progress->admit('queue', 'Start on a worker', 'Waiting for a worker', 'Started on a worker');
        $operation = $progress->during('queue', function () use ($follower, $operation): OperationResponse {
            while ($operation->status === 'queued') {
                $operation = $follower->next($operation);
            }

            return $operation;
        });
        $progress->complete('queue', $operation->startedAt === null ? ProgressState::Skipped : ProgressState::Success, "Operation {$operation->id}.");
        $this->absorb($progress, $operation);

        while (! $operation->terminal()) {
            $this->settleFinishedSteps($progress);
            $running = $this->currentStep();

            if ($running === null) {
                $operation = $follower->next($operation);
                $this->absorb($progress, $operation);

                continue;
            }

            $operation = $progress->during($running, function () use ($follower, $operation, $progress, $running): OperationResponse {
                do {
                    $operation = $follower->next($operation);
                    $this->absorb($progress, $operation);
                } while (! $operation->terminal() && in_array($this->operationSteps[$running]->status ?? 'running', ['pending', 'running', 'waiting'], true));

                return $operation;
            });
            $this->settleStep($progress, $running, started: true);
        }

        $this->settleFinishedSteps($progress, final: true);
        $progress->finish(match ($operation->status) {
            'succeeded' => $operation->kind === 'tasks.check' ? match ($operation->result['outcome'] ?? null) {
                'passed' => 'Check passed.',
                'failed' => 'Check failed.',
                'changed' => 'Check changed the tree.',
                default => 'Check operation succeeded.',
            } : "{$verb} succeeded.",
            'cancelled' => "{$verb} cancelled.",
            default => "{$verb} failed.",
        });
        $this->writeOperationSummary($operation);

        return $operation->succeeded() ? self::SUCCESS : self::FAILURE;
    }

    /** Merges the changed steps of a read, admits new ones, and prints their new output. */
    private function absorb(ProgressDisplay $progress, OperationResponse $operation): void
    {
        $steps = $operation->steps;
        usort($steps, static fn (OperationStepResponse $left, OperationStepResponse $right): int => $left->position <=> $right->position);

        foreach ($steps as $step) {
            if (! isset($this->operationSteps[$step->name])) {
                $progress->admit($step->name, ...OperationStepLabels::for($step->name));
            }

            $this->operationSteps[$step->name] = $step;

            foreach ($this->outputTail?->lines($step->name, $step->output) ?? [] as $line) {
                $this->writeOperationOutputLine($step->name, $line);
            }
        }

        uasort($this->operationSteps, static fn (OperationStepResponse $left, OperationStepResponse $right): int => $left->position <=> $right->position);
    }

    private function writeOperationOutputLine(string $step, string $line): void
    {
        $this->writeOperationOutput("{$step}: ".json_encode($line, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** The first step that runs or waits to run, if it has not settled yet. */
    private function currentStep(): ?string
    {
        foreach ($this->operationSteps as $name => $step) {
            if (isset($this->settledSteps[$name])) {
                continue;
            }

            if (in_array($step->status, ['running', 'waiting'], true)) {
                return $name;
            }

            if ($step->status === 'pending') {
                return null;
            }
        }

        return null;
    }

    /** Settles, in order, every step that already ended between two reads. */
    private function settleFinishedSteps(ProgressDisplay $progress, bool $final = false): void
    {
        foreach ($this->operationSteps as $name => $step) {
            if (isset($this->settledSteps[$name])) {
                continue;
            }

            if (! $final && in_array($step->status, ['pending', 'running', 'waiting'], true)) {
                return;
            }

            $this->settleStep($progress, $name, started: false);
        }
    }

    private function settleStep(ProgressDisplay $progress, string $name, bool $started): void
    {
        $step = $this->operationSteps[$name] ?? null;
        $this->settledSteps[$name] = true;

        if ($step === null || in_array($step->status, ['pending', 'cancelled'], true)) {
            $progress->complete($name, ProgressState::Skipped, $step?->status === 'cancelled' ? 'Cancelled.' : 'Not reached.');

            return;
        }

        if (! $started) {
            $progress->during($name, static fn (): null => null);
        }

        $progress->complete($name, match ($step->status) {
            'succeeded' => ProgressState::Success,
            'running', 'waiting' => ProgressState::Warning,
            default => ProgressState::Failure,
        }, $step->errorCode ?? '');
    }

    private function writeOperationSummary(OperationResponse $operation): void
    {
        if ($operation->failedStep !== null) {
            $this->writeHumanMessage('Failed step: '.$operation->failedStep);
        }

        if ($operation->errorCode !== null) {
            $this->writeHumanMessage('Error code: '.$operation->errorCode);
        }

        if ($operation->kind === 'tasks.check') {
            foreach (['check_id' => 'Check', 'commit' => 'Commit'] as $field => $label) {
                $value = $operation->result[$field] ?? null;
                if (is_int($value) || is_string($value)) {
                    $this->writeHumanMessage($label.': '.$value);
                }
            }
        }

        if ($operation->kind === 'tasks.publish') {
            foreach (['branch' => 'Branch', 'commit' => 'Commit', 'pull_request_url' => 'Pull request'] as $field => $label) {
                $value = $operation->result[$field] ?? null;
                if (is_string($value)) {
                    $this->writeHumanMessage($label.': '.$value);
                }
            }
        }

        $selected = $operation->result['selected_release'] ?? null;

        if (is_string($selected)) {
            $this->writeHumanMessage('Selected release: '.$selected);
        }

        $this->writeHumanMessage('Operation: '.$operation->id);
        $this->writeHumanMessage('Request ID: '.$operation->requestId);
    }

    /** Prints a line of step output above the live tree, without redrawing it. */
    private function writeOperationOutput(string $message): void
    {
        if ($this->consoleMode()->machine) {
            return;
        }

        Animation::printLine($this->output, implode("\n", TerminalText::wrap(
            TerminalText::safe($message),
            $this->consoleMode()->columns,
        ))."\n");
    }

    /** The operation's own channel, or null when the Gateway has no Reverb, so the follower polls. */
    private function operationSubscriber(GatewayConnector $connector, GatewayProfile $profile, int $operationId): ?RealtimeSubscriber
    {
        $config = RealtimeConnectionConfig::resolve($profile, app()->version())
            ?? RealtimeConnectionConfig::resolve($this->discoveredRealtimeProfile($connector, $profile), app()->version());

        if ($config === null) {
            return null;
        }

        return new RealtimeSubscriber(
            app(WebSocketTransport::class),
            $config,
            new AuthorizedRealtimeChannel("private-operation.{$operationId}", new GatewayChannelAuthorizer($config->gatewayUrl, $config->caPath)),
        );
    }
}
