<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersDeployResponses;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Deploy\RemoveDeployStepRequest;
use App\Http\Gateway\Responses\Deploy\DeployResponse;
use App\Services\Deploy\DeployManager;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('deploy:step-remove
    {app? : Production app name or domain}
    {step? : Deployment step id or exact title}
    {--force : Skip interactive confirmation}
    {--json : Output JSON}')]
#[Description('Remove a deployment pipeline step from a production app')]
class DeployStepRemoveCommand extends Command
{
    use RendersDeployResponses;

    public function handle(DeployManager $deploy, CallerRoleResolver $roles): int
    {
        $callerRole = $roles->resolve();

        if (in_array($callerRole, ['app', 'unknown'], true)) {
            return $this->failCommand('caller_role_not_allowed', 'This command may only be run from a control or gateway node.', ['caller_role' => $callerRole]);
        }

        $app = $this->stringArgument('app');
        $step = $this->stringArgument('step');

        if ($app === null || $step === null) {
            return $this->failCommand('validation_failed', 'App and step are required.', ['field' => $app === null ? 'app' : 'step']);
        }

        if ($this->option('force') !== true) {
            return $this->failCommand('destructive_consent_required', 'Use --force to remove this deployment step.', ['field' => 'force']);
        }

        try {
            if ($callerRole !== 'gateway') {
                /** @var DeployResponse $dto */
                $dto = app(GatewayConnector::class)
                    ->send(new RemoveDeployStepRequest($app, $step))
                    ->dto();

                $result = [
                    'step' => $dto->data['step'] ?? [],
                    'meta' => $dto->meta,
                ];
            } else {
                $result = $deploy->removeStep($app, $step);
            }
        } catch (GatewayApiException $exception) {
            return $this->failCommand($exception->errorCode() ?? 'gateway_unavailable', $exception->getMessage(), $exception->errorMeta(), $exception->errorData());
        } catch (\Throwable) {
            return $this->failCommand('gateway_unavailable', 'Gateway connection is required to manage deployment policy.', []);
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['step' => $result['step']], $result['meta']);
        }

        $this->line("Removed deployment step {$result['step']['title']} from {$result['step']['app']}.");

        return self::SUCCESS;
    }
}
