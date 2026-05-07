<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersDeployResponses;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Deploy\RunDeployRequest;
use App\Http\Gateway\Responses\Deploy\DeployResponse;
use App\Services\Deploy\DeployManager;
use App\Services\Nodes\CallerRoleResolver;
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

    public function handle(DeployManager $deploy, CallerRoleResolver $roles): int
    {
        $callerRole = $roles->resolve();

        if (in_array($callerRole, ['app', 'unknown'], true)) {
            return $this->failCommand('caller_role_not_allowed', 'This command may only be run from a control or gateway node.', ['caller_role' => $callerRole]);
        }

        $app = $this->stringArgument('app');

        if ($app === null) {
            return $this->failCommand('validation_failed', 'App is required.', ['field' => 'app']);
        }

        try {
            if ($callerRole !== 'gateway') {
                /** @var DeployResponse $dto */
                $dto = app(GatewayConnector::class)
                    ->send(new RunDeployRequest($app, $this->option('detach') === true))
                    ->dto();

                $result = [
                    'run' => $dto->data['run'] ?? [],
                    'meta' => $dto->meta,
                ];

                if (isset($dto->data['output'])) {
                    $result['output'] = $dto->data['output'];
                }
            } else {
                $result = $deploy->run($app, $this->option('detach') === true);
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

        if ($this->wantsJson()) {
            return $this->jsonSuccess($data, $result['meta']);
        }

        $this->line("Deployment {$result['run']['status']} for {$result['run']['app']}.");

        return self::SUCCESS;
    }
}
