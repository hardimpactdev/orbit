<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Actions\Tools\PrepareVpEnvironmentAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\Deployment\DeploymentEvent;
use App\Domain\Instances\Deployment\DeploymentOutputStream;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Instances\Deployment\DevelopmentTarget;
use App\Domain\Logs\LogRedactor;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Instance;
use Illuminate\Support\Facades\Config;

/**
 * Deploys a development default in its own checkout through the Node agent's `instances.*-development-*` commands
 * (docs/reference/deployments.md#development-deploy-steps). Each step runs as the managed account and publishes its
 * output live on the operation's channel.
 */
final readonly class RemoteDevelopmentDeployment implements DevelopmentDeployment
{
    /** The checkout refusals that each have their own error. */
    private const array REFUSALS = [
        'instances.checkout_dirty' => ['deployment.checkout_dirty', 'The checkout has uncommitted changes to tracked files.'],
        'instances.branch_diverged' => ['deployment.branch_diverged', 'The local default branch has commits that the target commit does not contain.'],
        'instances.lifecycle_busy' => ['instance.lifecycle_busy', 'A setup or teardown step is running in the checkout.'],
    ];

    public function __construct(
        private AgentCommandRunner $agents,
        private ManagedUserAccountResolver $accounts,
        private CheckoutRemovalBoundary $removal,
        private RepositoryReadAccess $access,
        private PrepareVpEnvironmentAction $environments,
    ) {}

    public function target(Instance $instance, ?string $commit = null): DevelopmentTarget
    {
        if ($commit !== null) {
            $this->commit($commit);
        }
        $checkout = $this->checkoutInput($instance);
        try {
            $result = $this->run($instance, 'instances.resolve-development-target', [
                ...$checkout,
                'branch' => $this->branch($instance),
                'commit' => $commit,
                'protocols' => array_values(array_filter(Config::array('orbit.git.checkout_protocols', ['https']), is_string(...))),
                'git_read' => $this->access->for($checkout['repository'], $instance->project->source_access),
            ], 'development-deployment-target', 'deployment.prepare_failed');
        } catch (RuntimeConvergenceException $exception) {
            if ($commit !== null && AgentStep::refusal($exception)?->errorCode === 'instances.commit_not_on_branch') {
                throw new ResourceOperationException('deployment.commit_not_on_branch', "The default branch does not contain the commit [{$commit}].", 409, $exception);
            }

            throw $exception;
        }
        $target = $this->commit(is_string($result['commit'] ?? null) ? $result['commit'] : '');
        if ($commit !== null && $target !== $commit) {
            throw $this->invalidOutput();
        }

        return new DevelopmentTarget($target);
    }

    public function checkout(Instance $instance, string $commit, DeploymentRequest $request): void
    {
        $this->commit($commit);
        try {
            $result = $this->run($instance, 'instances.checkout-development-default', [
                ...$this->checkoutInput($instance),
                'branch' => $this->branch($instance),
                'commit' => $commit,
            ], 'development-deployment-checkout', 'deployment.checkout_failed');
        } catch (ResourceOperationException $exception) {
            // The deploy output names the changed files, as the operator needs them to clean the checkout.
            $changes = $exception->getPrevious()?->getPrevious();
            if ($changes instanceof AgentCommandRefused && is_string($changes->details['changes'] ?? null)) {
                $request->emit(new DeploymentEvent('checkout', DeploymentOutputStream::Stderr, "The checkout has uncommitted changes to tracked files:\n{$changes->details['changes']}\n"));
            }

            throw $exception;
        }
        if (($result['commit'] ?? null) !== $commit) {
            throw $this->invalidOutput();
        }
    }

    public function executeStep(Instance $instance, DevelopmentDeployStep $step, DeploymentRequest $request): CommandResult
    {
        $stepName = 'deployment-step-'.$step->name;
        // The Node publishes each line live on the operation's channel; the result keeps the output for the record.
        $result = $this->run($instance, 'instances.run-development-step', [
            ...$this->checkoutInput($instance),
            'script' => $this->environments->command($instance, $instance->checkout_path, $step->name, $step->command),
            'step' => $step->name,
            'timeout_seconds' => $step->timeoutSeconds,
            'operation' => $request->operation,
            'secrets' => app(LogRedactor::class)->valuesFor($instance),
            // The agent stops the step at its timeout; the rest leaves time for that stop and the result.
        ], $stepName, 'deployment.step_failed', $step->timeoutSeconds + 30);
        $streams = ['stdout' => '', 'stderr' => ''];

        foreach (is_array($result['output'] ?? null) ? $result['output'] : [] as $chunk) {
            $stream = is_array($chunk) ? ($chunk['stream'] ?? null) : null;
            $value = is_array($chunk) ? ($chunk['value'] ?? null) : null;
            if (! is_string($value) || ! in_array($stream, ['stdout', 'stderr'], true)) {
                throw $this->invalidOutput();
            }
            $streams[$stream] .= $value;
            $request->emit(new DeploymentEvent($step->name, $stream === 'stdout' ? DeploymentOutputStream::Stdout : DeploymentOutputStream::Stderr, $value));
        }

        $exitCode = is_int($result['exit_code'] ?? null) ? $result['exit_code'] : throw $this->invalidOutput();
        $output = new CommandResult($exitCode, $streams['stdout'], $streams['stderr'], 0, ($result['truncated'] ?? false) === true);

        if ($exitCode !== 0) {
            throw AgentStep::failed($instance->node, $stepName, 'deployment.step_failed', 'App development', $output);
        }

        return $output;
    }

    private function branch(Instance $instance): string
    {
        $instance->loadMissing('project');
        $branch = $instance->project->default_branch;
        if (! is_string($branch) || ! GitBranchName::isValid($branch)) {
            throw $this->invalidOutput();
        }

        return $branch;
    }

    /** @return array{home: string, repository: string, instance: int} */
    private function checkoutInput(Instance $instance): array
    {
        $instance->loadMissing(['project', 'node']);
        $this->removal->instanceRoot($instance, $this->accounts->resolve($instance->node));
        if (! $instance->placedOnAppDev() || $instance->name !== 'default') {
            throw $this->invalidOutput();
        }

        return ['home' => $instance->checkout_path, 'repository' => GitRepositoryOrigin::validate($instance->project->repository_url), 'instance' => $instance->id];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function run(Instance $instance, string $command, array $input, string $step, string $errorCode, ?int $deadlineSeconds = null): array
    {
        try {
            return AgentStep::run($this->agents, $instance->node, $command, $input, $step, $errorCode, 'App development', $deadlineSeconds);
        } catch (RuntimeConvergenceException $exception) {
            $known = self::REFUSALS[AgentStep::refusal($exception)->errorCode ?? ''] ?? null;

            throw $known === null ? $exception : new ResourceOperationException($known[0], $known[1], 409, $exception);
        }
    }

    private function commit(string $commit): string
    {
        if (preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/', $commit) !== 1) {
            throw $this->invalidOutput();
        }

        return $commit;
    }

    private function invalidOutput(): ResourceOperationException
    {
        return new ResourceOperationException('deployment.invalid_output', 'Development deployment returned invalid output.', 409);
    }
}
