<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Instances\Deployment\DeploymentEvent;
use App\Domain\Instances\Deployment\DeploymentOutputStream;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\DeploymentReleaseState;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DeploymentStep;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentResult;
use App\Domain\Instances\Environment\InstanceEnvironmentSynchronizer;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Closure;

final readonly class Orb220DeploymentApiFixture
{
    public function __construct(
        public Node $caller,
        public Node $owner,
        public Instance $instance,
        public Orb220ProductionDeployment $deployment,
    ) {}

    /** @param list<array{name: string, phase: string, command: string, timeout_seconds: int}>|null $steps */
    public static function create(?array $steps = null): self
    {
        $caller = Node::query()->create([
            'name' => 'deployment-stream-caller',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.220',
            'wireguard_ip' => '10.44.0.220',
            'user' => 'orbit',
        ]);
        $caller->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
        $owner = Node::query()->create([
            'name' => 'deployment-stream-owner',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.221',
            'wireguard_ip' => '10.44.0.221',
            'user' => 'orbit',
        ]);
        $owner->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
        $caller->accessibleNodes()->attach($owner->id);
        $project = Project::query()->create([
            'name' => 'Deployment Stream',
            'slug' => 'deployment-stream',
            'repository_url' => 'https://example.test/deployment-stream.git',
            'default_branch' => 'main',
            'root' => 'public',
        ]);
        $instance = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $owner->id,
            'name' => 'production',
            'environment' => 'production',
            'source_layout' => 'release',
            'checkout_path' => '/home/deployment-stream/releases/initial',
            'production_user' => 'deployment-stream',
            'production_home' => '/home/deployment-stream',
            'root' => 'public',
            'branch' => 'main',
            'branch_override' => 'main',
            'selected_php_version' => '8.5',
            'source_is_laravel' => true,
            'provisioning_step' => 'active',
            'status' => 'active',
        ]);
        $instance->update(ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5')->attributes());
        store_deploy_steps($instance, $steps ?? [
            [
                'name' => 'prepare',
                'phase' => 'before_activation',
                'command' => 'prepare-command',
                'timeout_seconds' => 30,
            ],
            [
                'name' => 'finish',
                'phase' => 'after_activation',
                'command' => 'finish-command',
                'timeout_seconds' => 30,
            ],
        ]);
        $deployment = new Orb220ProductionDeployment;

        app()->instance(ProductionDeployment::class, $deployment);
        app()->instance(InstanceEnvironmentOperationLock::class, new Orb220DeploymentLock);
        app()->instance(InstanceEnvironmentSynchronizer::class, new Orb220EnvironmentSynchronizer);
        app()->instance(ProductionPhpRuntimeManager::class, new Orb220PhpRuntimeManager);

        return new self($caller, $owner, $instance->fresh(), $deployment);
    }
}

final class Orb220ProductionDeployment implements ProductionDeployment
{
    public int $invocations = 0;

    public bool $failPreparation = false;

    public int $activations = 0;

    public ?string $requestedRelease = null;

    public bool $failRollback = false;

    /** @var array<string, string> */
    public array $commits = [];

    /** @var list<string> */
    public array $retainedReleases = ['fresh', 'initial'];

    /** Runs inside source preparation, such as a cancel request that lands while the deployment works. */
    public ?Closure $duringPrepare = null;

    /** Runs inside a rollback's release lookup, before activation. */
    public ?Closure $duringRetained = null;

    public ?Closure $duringSelection = null;

    /** Runs inside activation, after `current` points at the new release. */
    public ?Closure $duringActivation = null;

    /** Makes each read of the selection a remote command that the command deadline bounds. */
    public bool $readsAsRemoteCommand = false;

    /** The release `current` selects on the Node. */
    public string $current = 'initial';

    /** @var list<string> */
    public array $steps = [];

    /** How many 16 KiB stdout chunks the `prepare` step emits. */
    public int $prepareOutputChunks = 1;

    /** @var list<string|null> The commit each preparation was pinned to, or null for the branch's newest commit. */
    public array $preparedCommits = [];

    public function prepare(Instance $instance, string $branch, ?string $commit = null): DeploymentRelease
    {
        $this->invocations++;
        $this->preparedCommits[] = $commit;

        if ($this->duringPrepare !== null) {
            ($this->duringPrepare)();
        }

        if ($this->failPreparation) {
            throw new ResourceOperationException('deployment.prepare_failed', 'Injected preparation failure.', 409);
        }

        return $this->release('fresh', $commit);
    }

    public function executeStep(
        Instance $instance,
        DeploymentRelease $release,
        DeploymentStep $step,
        DeploymentRequest $request,
    ): CommandResult {
        $this->steps[] = $step->name;

        for ($chunk = 0; $chunk < ($step->name === 'prepare' ? $this->prepareOutputChunks : 1); $chunk++) {
            $request->emit(new DeploymentEvent(
                $step->name,
                $step->name === 'prepare' ? DeploymentOutputStream::Stdout : DeploymentOutputStream::Stderr,
                $step->name === 'prepare' ? str_repeat("\xff", 16 * 1024) : "output-secret\0bytes",
            ));
        }

        return new CommandResult(0, '', '', 1, false);
    }

    public function activate(Instance $instance, DeploymentRelease $release): DeploymentRelease
    {
        $this->activations++;
        $this->current = $release->name;

        if ($this->duringActivation !== null) {
            ($this->duringActivation)();
        }

        return $release;
    }

    public function selected(Instance $instance): ?DeploymentRelease
    {
        if ($this->duringSelection !== null) {
            ($this->duringSelection)();
        }
        if ($this->readsAsRemoteCommand) {
            // A real read is a remote command, bounded by the command deadline.
            app(CommandDeadline::class)->cap(30.0);
        }

        return $this->release($this->current);
    }

    public function retained(Instance $instance, string $name): DeploymentRelease
    {
        $this->invocations++;
        $this->requestedRelease = $name;

        if ($this->duringRetained !== null) {
            ($this->duringRetained)();
        }

        if ($this->failRollback) {
            throw new ResourceOperationException('rollback.release_invalid', 'Injected rollback failure.', 409);
        }

        return $this->release($name);
    }

    public function releases(Instance $instance): DeploymentReleaseState
    {
        return new DeploymentReleaseState($this->retainedReleases, $this->current);
    }

    public function prune(Instance $instance, DeploymentRelease $selected, ?DeploymentRelease $previous): array
    {
        return ['removed' => [], 'in_use' => []];
    }

    private function release(string $name, ?string $commit = null): DeploymentRelease
    {
        return new DeploymentRelease(
            $name,
            "/home/deployment-stream/releases/{$name}",
            $commit ?? ($this->commits[$name] ?? str_repeat('a', 40)),
        );
    }
}

final readonly class Orb220DeploymentLock implements InstanceEnvironmentOperationLock
{
    public function run(array $instanceIds, Closure $operation): mixed
    {
        return $operation();
    }
}

final readonly class Orb220EnvironmentSynchronizer implements InstanceEnvironmentSynchronizer
{
    public function execute(Instance $instance): InstanceEnvironmentResult
    {
        return new InstanceEnvironmentResult($instance->id, 'sync', false, 0);
    }
}

final readonly class Orb220PhpRuntimeManager implements ProductionPhpRuntimeManager
{
    public function converge(Instance $instance, ?Route $activating = null): void {}

    public function convergeMonitoring(Instance $instance, bool $enabled): void {}

    public function refreshCache(Instance $instance): void {}

    public function remove(Instance $instance): void {}
}
