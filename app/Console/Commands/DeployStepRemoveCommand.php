<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\HandlesPromptCancellation;
use App\Console\Commands\Concerns\RendersDeployResponses;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Deploy\RemoveDeployStepRequest;
use App\Http\Gateway\Responses\Deploy\DeployResponse;
use App\Models\App;
use App\Models\DeployStep;
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
    use HandlesPromptCancellation;
    use RendersDeployResponses;

    public function handle(DeployManager $deploy, CallerRoleResolver $roles): int
    {
        $callerRole = $roles->resolve();

        $app = $this->stringArgument('app');
        $step = $this->stringArgument('step');

        $isInteractive = ! $this->wantsJson() && $this->input->isInteractive();

        if ($app === null || $step === null) {
            if (! $isInteractive) {
                return $this->failCommand('validation_failed', 'App and step are required.', ['field' => $app === null ? 'app' : 'step']);
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

                if ($step === null) {
                    $step = $this->promptSearch(
                        label: 'Step',
                        options: fn (string $value): array => DeployStep::query()
                            ->whereHas('app', fn ($q) => $q->where('name', $app))
                            ->where(fn ($q) => $q->where('title', 'like', "%{$value}%")->orWhere('id', 'like', "%{$value}%"))
                            ->get()
                            ->mapWithKeys(fn (DeployStep $s): array => [(string) $s->id => "{$s->title} (#{$s->id})"])
                            ->all(),
                        placeholder: 'step title or id',
                    );
                }
            } catch (PromptAborted) {
                return $this->failCommand('validation_failed', 'Operation cancelled.', []);
            }
        }

        if ($this->option('force') !== true) {
            if (! $isInteractive) {
                return $this->failCommand('destructive_consent_required', 'Use --force to remove this deployment step.', ['field' => 'force']);
            }

            try {
                $confirmed = $this->promptConfirm("Remove deployment step '{$step}' from '{$app}'?", default: false);
            } catch (PromptAborted) {
                return $this->failCommand('validation_failed', 'Operation cancelled.', []);
            }

            if (! $confirmed) {
                return $this->failCommand('destructive_consent_required', 'Use --force to remove this deployment step.', ['field' => 'force']);
            }
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
