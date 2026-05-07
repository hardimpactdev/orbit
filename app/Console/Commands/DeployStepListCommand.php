<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersDeployResponses;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Deploy\ListDeployStepsRequest;
use App\Http\Gateway\Responses\Deploy\DeployResponse;
use App\Services\Deploy\DeployManager;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function Laravel\Prompts\table;

#[Signature('deploy:step-list
    {app? : Production app name or domain}
    {--json : Output JSON}')]
#[Description('List deployment pipeline steps for a production app')]
class DeployStepListCommand extends Command
{
    use RendersDeployResponses;

    public function handle(DeployManager $deploy, CallerRoleResolver $roles): int
    {
        $callerRole = $roles->resolve();

        if ($callerRole === 'unknown') {
            return $this->failCommand('caller_role_not_allowed', 'This command may only be run from a known Orbit node.', ['caller_role' => 'unknown']);
        }

        $app = $this->stringArgument('app');

        if ($app === null) {
            return $this->failCommand('validation_failed', 'App is required.', ['field' => 'app']);
        }

        try {
            if ($callerRole !== 'gateway') {
                /** @var DeployResponse $dto */
                $dto = app(GatewayConnector::class)
                    ->send(new ListDeployStepsRequest($app))
                    ->dto();

                $result = [
                    'steps' => $dto->data['steps'] ?? [],
                    'meta' => $dto->meta,
                ];
            } else {
                $result = $deploy->listSteps($app);
            }
        } catch (GatewayApiException $exception) {
            return $this->failCommand($exception->errorCode() ?? 'gateway_unavailable', $exception->getMessage(), $exception->errorMeta(), $exception->errorData());
        } catch (\Throwable) {
            return $this->failCommand('gateway_unavailable', 'Gateway connection is required to list deployment policy.', []);
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['steps' => $result['steps']], $result['meta']);
        }

        if ($result['steps'] === []) {
            $this->line("No deployment steps defined for {$result['meta']['app']}.");

            return self::SUCCESS;
        }

        table(
            headers: ['ID', 'ORDER', 'TITLE', 'COMMAND', 'TIMEOUT'],
            rows: array_map(fn (array $step): array => [
                $step['id'],
                $step['order'],
                $step['title'],
                $step['command'],
                "{$step['timeout_seconds']}s",
            ], $result['steps']),
        );

        return self::SUCCESS;
    }
}
