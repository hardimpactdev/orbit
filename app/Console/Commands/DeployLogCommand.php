<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersDeployResponses;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Deploy\ShowDeployLogRequest;
use App\Http\Gateway\Responses\Deploy\DeployResponse;
use App\Services\Deploy\DeployManager;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('deploy:log
    {app? : Production app name or domain}
    {run? : Deployment run id}
    {--step= : Deployment run step id}
    {--lines=500 : Lines per captured stream}
    {--json : Output JSON}')]
#[Description('Show stored deployment output for a production app run')]
class DeployLogCommand extends Command
{
    use RendersDeployResponses;

    public function handle(DeployManager $deploy, CallerRoleResolver $roles): int
    {
        $callerRole = $roles->resolve();

        if ($callerRole === 'unknown') {
            return $this->failCommand('caller_role_not_allowed', 'This command may only be run from a known Orbit node.', ['caller_role' => 'unknown']);
        }

        $app = $this->stringArgument('app');
        $run = $this->stringArgument('run');

        if ($app === null || $run === null || ! ctype_digit($run) || (int) $run < 1) {
            return $this->failCommand('validation_failed', 'App and positive run id are required.', ['field' => $app === null ? 'app' : 'run']);
        }

        $step = $this->optionalPositiveIntOption('step');
        $lines = $this->positiveIntOption('lines', 500);

        foreach (['step' => $step, 'lines' => $lines] as $field => $value) {
            if ($value === false) {
                return $this->failCommand('validation_failed', "Invalid value for --{$field}: must be a positive integer.", ['field' => $field]);
            }
        }

        try {
            if ($callerRole !== 'gateway') {
                /** @var DeployResponse $dto */
                $dto = app(GatewayConnector::class)
                    ->send(new ShowDeployLogRequest($app, (int) $run, $step, $lines))
                    ->dto();

                $result = [
                    'run' => $dto->data['run'] ?? [],
                    'steps' => $dto->data['steps'] ?? [],
                    'meta' => $dto->meta,
                ];
            } else {
                $result = $deploy->log($app, (int) $run, $step, $lines);
            }
        } catch (GatewayApiException $exception) {
            return $this->failCommand($exception->errorCode() ?? 'gateway_unavailable', $exception->getMessage(), $exception->errorMeta(), $exception->errorData());
        } catch (\Throwable) {
            return $this->failCommand('gateway_unavailable', 'Gateway connection is required to read deployment logs.', []);
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess([
                'run' => $result['run'],
                'steps' => $result['steps'],
            ], $result['meta']);
        }

        foreach ($result['steps'] as $logStep) {
            $this->line("{$logStep['title']} ({$logStep['status']}):");
            $this->line((string) $logStep['output']['stdout']);
            $this->line((string) $logStep['output']['stderr']);
        }

        return self::SUCCESS;
    }
}
