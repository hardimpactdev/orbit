<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Commands\GatewayCommand;
use App\Data\GatewayProfile;
use App\Support\Console\Animation;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\InterruptIntent;
use App\Support\Console\ProgressState;
use App\Support\Console\TerminalText;
use App\Support\Operations\OperationFollower;
use App\Support\Realtime\AuthorizedRealtimeChannel;
use App\Support\Realtime\GatewayChannelAuthorizer;
use App\Support\Realtime\RealtimeConnectionConfig;
use App\Support\Realtime\RealtimeSubscriber;
use App\Support\Realtime\WebSocketTransport;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Throwable;

/**
 * Follows the parent native result. Ctrl-C stops following and leaves the Operation running.
 *
 * @phpstan-require-extends GatewayCommand
 */
trait FollowsOperations
{
    protected function followOperation(GatewayConnector $connector, GatewayProfile $profile, OperationResponse $started, string $title, string $verb): int
    {
        $follower = new OperationFollower(
            fn (): OperationResponse => $this->sendOrThrow($connector, new ShowOperationRequest($started->id), OperationResponse::class),
            $this->operationSubscriber($connector, $profile, $started->id),
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
        $progress = $this->progressDisplay($title);
        $progress->admit('native', $verb, "{$verb} running", "{$verb} complete");
        $operation = $progress->during('native', function () use ($follower, $operation): OperationResponse {
            while (! $operation->terminal()) {
                $operation = $follower->next($operation);
            }

            return $operation;
        });
        $progress->complete('native', match ($operation->status) {
            'succeeded' => ProgressState::Success,
            'cancelled' => ProgressState::Skipped,
            default => ProgressState::Failure,
        }, $operation->errorCode ?? '');
        $progress->finish(match ($operation->status) {
            'succeeded' => $operation->kind === 'tasks.check' ? match ($operation->result['outcome'] ?? null) {
                'passed' => 'Check passed.',
                'failed' => 'Check failed.',
                'changed' => 'Check changed the tree.',
                default => 'Check operation succeeded.',
            } : ($operation->kind === 'tasks.analyze' ? match ($operation->result['outcome'] ?? null) {
                'ready' => 'Analysis ready. This is evidence, not approval.',
                'action_required' => 'Analysis requires action. This is not approval.',
                'blocked' => 'Analysis blocked. This is not approval.',
                'changed' => 'Analysis changed the tree. This is not usable turn evidence.',
                default => 'Analysis operation succeeded. Inspect its evidence.',
            } : "{$verb} succeeded."),
            'cancelled' => "{$verb} cancelled.",
            default => "{$verb} failed.",
        });
        $this->writeOperationSummary($operation);

        return $operation->succeeded() ? self::SUCCESS : self::FAILURE;
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

        if ($operation->kind === 'instance.sync_environment') {
            $refresh = $operation->result['runtime_refresh'] ?? null;
            $restarted = $operation->result['restarted_process_ids'] ?? null;

            if (is_string($refresh)) {
                $this->writeHumanMessage('Production PHP: '.match ($refresh) {
                    'config_cache_and_opcache_refreshed' => 'config cache rebuilt; FPM opcache reset',
                    'opcache_refreshed_config_uncached' => 'FPM opcache reset; config remains uncached',
                    'skipped_unchanged' => 'refresh skipped: environment file unchanged',
                    'skipped_no_php_version' => 'refresh skipped: no PHP version selected',
                    'skipped_no_current_release' => 'refresh skipped: no current release',
                    default => $refresh,
                });
            }

            if (is_array($restarted)) {
                $this->writeHumanMessage('Processes restarted: '.($restarted === [] ? 'none' : implode(', ', array_filter($restarted, is_int(...)))));
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
