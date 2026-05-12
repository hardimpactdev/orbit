<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersDeployResponses;
use App\Http\Gateway\DeployRunGatewayStreamClient;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Deploy\RunDeployRequest;
use App\Http\Gateway\Responses\Deploy\DeployResponse;
use App\Services\Deploy\DeployManager;
use App\Services\Nodes\CallerRoleResolver;
use App\Support\Cli\RemoteProgressRenderer;
use App\Support\Cli\RemoteProgressReporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('deploy:run
    {app? : Production app name or domain}
    {--detach : Start and return after the run is durable}
    {--json : Output JSON}')]
#[Description('Run the deployment pipeline for a production app')]
class DeployRunCommand extends Command
{
    use RendersDeployResponses;

    public function handle(
        DeployManager $deploy,
        CallerRoleResolver $roles,
        DeployRunGatewayStreamClient $deployStream,
    ): int {
        $detach = $this->option('detach') === true;
        $callerRole = $roles->resolve();

        if (in_array($callerRole, ['app', 'unknown'], true)) {
            return $this->failCommand('caller_role_not_allowed', 'This command may only be run from a control or gateway node.', ['caller_role' => $callerRole]);
        }

        $app = $this->stringArgument('app');

        if ($app === null) {
            return $this->failCommand('validation_failed', 'App is required.', ['field' => 'app']);
        }

        if (! $this->wantsJson()) {
            return $callerRole === 'gateway'
                ? $this->runLocallyForHuman($deploy, $app, $detach)
                : $this->forwardRunForHuman($deployStream, $app, $detach);
        }

        try {
            if ($callerRole !== 'gateway') {
                /** @var DeployResponse $dto */
                $dto = app(GatewayConnector::class)
                    ->send(new RunDeployRequest($app, $detach))
                    ->dto();

                $result = [
                    'run' => $dto->data['run'] ?? [],
                    'meta' => $dto->meta,
                ];

                if (isset($dto->data['output'])) {
                    $result['output'] = $dto->data['output'];
                }
            } else {
                $result = $deploy->run($app, $detach);
            }
        } catch (GatewayApiException $exception) {
            return $this->failCommand(
                $exception->errorCode() ?? 'gateway_unavailable',
                $exception->getMessage(),
                $exception->errorMeta(),
                $exception->errorData(),
            );
        } catch (\Throwable) {
            return $this->failCommand('gateway_unavailable', 'Gateway connection is required to run deployments.', []);
        }

        $data = ['run' => $result['run']];

        if (isset($result['output'])) {
            $data['output'] = $result['output'];
        }

        return $this->jsonSuccess($data, $result['meta']);
    }

    private function runLocallyForHuman(DeployManager $deploy, string $app, bool $detach): int
    {
        $reporter = new RemoteProgressReporter(new RemoteProgressRenderer($this->output));

        try {
            $result = $deploy->run($app, $detach, $reporter);
        } catch (GatewayApiException $exception) {
            $reporter->finish('Deployment failed', false);

            return $this->failCommand(
                $exception->errorCode() ?? 'gateway_unavailable',
                $exception->getMessage(),
                $exception->errorMeta(),
                $exception->errorData(),
            );
        } catch (\Throwable) {
            $reporter->finish('Deployment failed', false);

            return $this->failCommand('gateway_unavailable', 'Gateway connection is required to run deployments.', []);
        }

        $reporter->finish($this->deploymentFooter($result), true);

        return $this->renderHumanSuccess($result);
    }

    private function forwardRunForHuman(DeployRunGatewayStreamClient $deployStream, string $app, bool $detach): int
    {
        $renderer = new RemoteProgressRenderer($this->output);
        $completeData = [];
        $errorData = [];
        $footer = 'Deployment completed';

        $result = $deployStream->run($app, $detach, function (string $event, array $payload) use ($renderer, &$completeData, &$errorData, &$footer): void {
            if ($event === 'tree') {
                $title = is_string($payload['title'] ?? null) ? $payload['title'] : '';
                $steps = is_array($payload['steps'] ?? null) ? $payload['steps'] : [];
                $renderer->tree($title, $steps);

                return;
            }

            if ($event === 'step') {
                $renderer->step(
                    is_string($payload['key'] ?? null) ? $payload['key'] : '',
                    is_string($payload['status'] ?? null) ? $payload['status'] : '',
                    is_string($payload['message'] ?? null) ? $payload['message'] : null,
                );

                return;
            }

            if ($event === 'complete') {
                $completeData = is_array($payload['data'] ?? null) ? $payload['data'] : [];

                if (is_string($completeData['footer'] ?? null)) {
                    $footer = $completeData['footer'];
                }

                return;
            }

            if ($event === 'error') {
                $errorData = is_array($payload['data'] ?? null) ? $payload['data'] : [];

                if (is_string($errorData['footer'] ?? null)) {
                    $footer = $errorData['footer'];
                }
            }
        });

        if ($result instanceof GatewayApiException) {
            return $this->failCommand(
                $result->errorCode() ?? 'gateway_unavailable',
                $result->getMessage() !== ''
                    ? $result->getMessage()
                    : 'Gateway connection is required to run deployments.',
                $result->errorMeta(),
                $result->errorData(),
            );
        }

        $renderer->finish($footer, $result === self::SUCCESS);

        if ($result !== self::SUCCESS) {
            return $this->failCommand(
                code: is_string($errorData['code'] ?? null) ? $errorData['code'] : 'deploy.execution_failed',
                message: is_string($errorData['message'] ?? null) ? $errorData['message'] : 'Deployment failed.',
                meta: is_array($errorData['meta'] ?? null) ? $errorData['meta'] : [],
                data: is_array($errorData['data'] ?? null) ? $errorData['data'] : [],
            );
        }

        $run = is_array($completeData['run'] ?? null) ? $completeData['run'] : [];

        if ($run === []) {
            return $this->failCommand('gateway_stream_invalid', 'Gateway stream completed without deployment run data.', []);
        }

        return $this->renderHumanSuccess(['run' => $run]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function deploymentFooter(array $result): string
    {
        $run = is_array($result['run'] ?? null) ? $result['run'] : [];

        return ($run['status'] ?? null) === 'running'
            ? 'Deployment started'
            : 'Deployment completed';
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderHumanSuccess(array $result): int
    {
        $run = is_array($result['run'] ?? null) ? $result['run'] : [];
        $app = is_string($run['app'] ?? null) ? $run['app'] : 'unknown';
        $status = is_string($run['status'] ?? null) ? $run['status'] : 'completed';
        $label = match ($status) {
            'running' => 'started',
            'completed' => 'completed',
            default => $status,
        };
        $runId = is_int($run['id'] ?? null) || is_string($run['id'] ?? null)
            ? " (run #{$run['id']})"
            : '';

        $this->line("Deployment {$label} for {$app}{$runId}.");

        return self::SUCCESS;
    }
}
