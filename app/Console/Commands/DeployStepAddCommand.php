<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\HandlesPromptCancellation;
use App\Console\Commands\Concerns\RendersDeployResponses;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Deploy\AddDeployStepRequest;
use App\Http\Gateway\Responses\Deploy\DeployResponse;
use App\Models\App;
use App\Models\DeployStep;
use App\Services\Deploy\DeployManager;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('deploy:step-add
    {app? : Production app name or domain}
    {deploy_command? : Shell command to run}
    {--title= : Display title}
    {--order= : Positive insertion order}
    {--timeout=600 : Timeout in seconds}
    {--retention= : Optional release retention metadata}
    {--json : Output JSON}')]
#[Description('Add a deployment pipeline step for a production app')]
class DeployStepAddCommand extends Command
{
    use HandlesPromptCancellation;
    use RendersDeployResponses;

    public function handle(DeployManager $deploy, CallerRoleResolver $roles): int
    {
        $callerRole = $roles->resolve();

        if (in_array($callerRole, ['app', 'unknown'], true)) {
            return $this->failCommand('caller_role_not_allowed', 'This command may only be run from a control or gateway node.', ['caller_role' => $callerRole]);
        }

        $app = $this->stringArgument('app');
        $command = $this->stringArgument('deploy_command');

        $isInteractive = ! $this->wantsJson() && $this->input->isInteractive();

        if ($app === null || $command === null) {
            if (! $isInteractive) {
                return $this->failCommand('validation_failed', 'App and command are required.', ['field' => $app === null ? 'app' : 'command']);
            }

            try {
                if ($app === null) {
                    $app = $this->promptSearch(
                        label: 'App',
                        options: fn (string $value): array => App::query()
                            ->where('environment', 'production')
                            ->where('name', 'like', "%{$value}%")
                            ->pluck('name', 'name')
                            ->all(),
                        placeholder: 'docs',
                    );
                }

                if ($command === null) {
                    $command = $this->promptText(
                        label: 'Command',
                        required: 'Command is required.',
                    );
                }
            } catch (PromptAborted) {
                return $this->failCommand('validation_failed', 'Operation cancelled.', []);
            }
        }

        $timeout = $this->positiveIntOption('timeout', DeployStep::DEFAULT_TIMEOUT_SECONDS);
        $order = $this->optionalPositiveIntOption('order');
        $retention = $this->optionalPositiveIntOption('retention');

        foreach (['timeout' => $timeout, 'order' => $order, 'retention' => $retention] as $field => $value) {
            if ($value === false) {
                return $this->failCommand('validation_failed', "Invalid value for --{$field}: must be a positive integer.", ['field' => $field]);
            }
        }

        try {
            if ($callerRole !== 'gateway') {
                /** @var DeployResponse $dto */
                $dto = app(GatewayConnector::class)
                    ->send(new AddDeployStepRequest($app, $command, $this->stringOption('title'), $order, $timeout, $retention))
                    ->dto();

                $result = [
                    'step' => $dto->data['step'] ?? [],
                    'meta' => $dto->meta,
                ];
            } else {
                $result = $deploy->addStep($app, $command, $this->stringOption('title'), $order, $timeout, $retention);
            }
        } catch (GatewayApiException $exception) {
            return $this->failCommand($exception->errorCode() ?? 'gateway_unavailable', $exception->getMessage(), $exception->errorMeta(), $exception->errorData());
        } catch (\Throwable) {
            return $this->failCommand('gateway_unavailable', 'Gateway connection is required to manage deployment policy.', []);
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['step' => $result['step']], $result['meta']);
        }

        $this->line("Added deployment step {$result['step']['order']} for {$result['step']['app']}: {$result['step']['title']}");

        return self::SUCCESS;
    }
}
