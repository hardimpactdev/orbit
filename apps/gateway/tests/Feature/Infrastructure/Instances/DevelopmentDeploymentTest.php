<?php

declare(strict_types=1);

use App\Actions\Instances\DeployDefaultInstanceAction;
use App\Actions\Instances\SelectInstanceSeedAction;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Projects\ProjectDevelopmentDeployStepStore;
use App\Models\Instance;
use Tests\Support\DevelopmentDeploymentFixture;

beforeEach(function (): void {
    $this->fixture = new DevelopmentDeploymentFixture;
    app()->instance(DevelopmentDeployment::class, $this->fixture->deployment);
});

afterEach(function (): void {
    $this->fixture->cleanup();
});

/** @param list<DevelopmentDeployStep> $steps */
function plain_steps(Instance $instance, array $steps): void
{
    foreach ($steps as $step) {
        app(ProjectDevelopmentDeployStepStore::class)->create($instance->project, $step, null, null);
    }
}

function plain_git(string $directory, string ...$arguments): string
{
    return trim(DevelopmentDeploymentFixture::command(['git', '-C', $directory, ...$arguments]));
}

describe('development defaults in place', function (): void {
    it('checks out the newest commit on the branch and keeps untracked files and linked worktrees', function (): void {
        $instance = $this->fixture->instance;
        $home = $this->fixture->home;
        plain_steps($instance, [new DevelopmentDeployStep('install', 'printf "%s" "$PWD" > step-ran-in')]);
        $commit = $this->fixture->push('second');

        $result = app(DeployDefaultInstanceAction::class)->execute($instance);

        expect($result->succeeded)->toBeTrue()
            ->and($result->commit)->toBe($commit)
            ->and(plain_git($home, 'rev-parse', 'HEAD'))->toBe($commit)
            ->and(plain_git($home, 'symbolic-ref', '--short', 'HEAD'))->toBe('main')
            ->and(file_get_contents($home.'/public/index.php'))->toContain('second')
            ->and(file_exists($home.'/old.txt'))->toBeFalse()
            ->and(file_exists($home.'/packages'))->toBeFalse()
            ->and(file_get_contents($home.'/step-ran-in'))->toBe($home)
            ->and(file_get_contents($home.'/.cache/warm'))->toBe('previous-cache')
            ->and(file_get_contents($home.'/.env'))->toBe("APP_ENV=development\n")
            ->and(file_exists($home.'/releases'))->toBeFalse()
            ->and(file_exists($home.'/current'))->toBeFalse()
            ->and(plain_git($this->fixture->sandbox.'/apps/dev935/t3code-ab12', 'rev-parse', 'HEAD'))->toBe($this->fixture->initialCommit)
            ->and($instance->fresh()->seed_path)->toBe($home)
            ->and($instance->fresh()->seed_commit)->toBe($commit);

        $consumer = Instance::query()->create([
            'project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'name' => 'feature',
            'checkout_path' => $this->fixture->sandbox.'/apps/dev935/feature', 'status' => 'reserved',
        ]);
        app(SelectInstanceSeedAction::class)->execute($consumer);
        expect($consumer->fresh()->seed_path)->toBe($home)
            ->and($consumer->fresh()->seed_commit)->toBe($commit)
            ->and($consumer->fresh()->seed_repository)->toBe($home);
    });

    it('leaves the checkout at the new commit after a failed step and reruns the steps on the next deployment', function (): void {
        $instance = $this->fixture->instance;
        $home = $this->fixture->home;
        plain_steps($instance, [new DevelopmentDeployStep('install', 'test -f allow-install; touch installed')]);
        $commit = $this->fixture->push('second');

        $failed = app(DeployDefaultInstanceAction::class)->execute($instance);

        expect($failed->failure?->errorCode)->toBe('deployment.step_failed')
            ->and(plain_git($home, 'rev-parse', 'HEAD'))->toBe($commit)
            ->and($instance->fresh()->seed_commit)->toBeNull();

        touch($home.'/allow-install');
        $retried = app(DeployDefaultInstanceAction::class)->execute($instance);

        expect($retried->succeeded)->toBeTrue()
            ->and(file_exists($home.'/installed'))->toBeTrue()
            ->and($instance->fresh()->seed_commit)->toBe($commit);
    });

    it('checks out a pinned commit that the fetched branch contains, and refuses one it does not', function (): void {
        $instance = $this->fixture->instance;
        $home = $this->fixture->home;
        $second = $this->fixture->push('second');
        $this->fixture->push('third');

        $pinned = app(DeployDefaultInstanceAction::class)->execute($instance, commit: $second);

        expect($pinned->succeeded)->toBeTrue()
            ->and($pinned->commit)->toBe($second)
            ->and(plain_git($home, 'rev-parse', 'HEAD'))->toBe($second)
            ->and($instance->fresh()->seed_commit)->toBe($second);

        $refused = app(DeployDefaultInstanceAction::class)->execute($instance, commit: str_repeat('f', 40));

        expect($refused->failure?->errorCode)->toBe('deployment.commit_not_on_branch')
            ->and($refused->commit)->toBe(str_repeat('f', 40))
            ->and(plain_git($home, 'rev-parse', 'HEAD'))->toBe($second);
    });

    it('refuses a checkout with uncommitted changes to tracked files and changes nothing', function (): void {
        $home = $this->fixture->home;
        file_put_contents($home.'/public/index.php', '<?php echo "local edit";');
        $this->fixture->push('second');
        $events = [];

        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance, new DeploymentRequest(static function ($event) use (&$events): void {
            $events[] = $event->value;
        }));

        expect($result?->failure?->errorCode)->toBe('deployment.checkout_dirty')
            ->and(plain_git($home, 'rev-parse', 'HEAD'))->toBe($this->fixture->initialCommit)
            ->and(file_get_contents($home.'/public/index.php'))->toBe('<?php echo "local edit";')
            ->and(implode('', $events))->toContain('public/index.php');
    });

    it('refuses a local branch with commits that the target commit does not contain', function (): void {
        $home = $this->fixture->home;
        plain_git($home, '-c', 'user.email=dev935@example.test', '-c', 'user.name=Local', 'commit', '--allow-empty', '-m', 'local only');
        $local = plain_git($home, 'rev-parse', 'HEAD');
        $this->fixture->push('second');

        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance);

        expect($result?->failure?->errorCode)->toBe('deployment.branch_diverged')
            ->and(plain_git($home, 'rev-parse', 'HEAD'))->toBe($local)
            ->and(plain_git($home, 'rev-parse', 'main'))->toBe($local);
    });

    it('runs back-to-back steps when an earlier step leaves a process behind', function (): void {
        plain_steps($this->fixture->instance, [
            new DevelopmentDeployStep('daemon', 'setsid -f sleep 5 >/dev/null 2>&1 </dev/null'),
            ...array_map(static fn (int $step): DevelopmentDeployStep => new DevelopmentDeployStep('step-'.$step, 'true'), range(1, 8)),
        ]);

        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance);

        expect($result?->succeeded)->toBeTrue()
            ->and($result?->commands)->toHaveCount(9);
    });

    it('waits for a setup step that runs in the checkout', function (): void {
        $this->fixture->push('second');
        $lock = DevelopmentDeploymentFixture::holdLifecycleLock($this->fixture->home);

        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance);
        fclose($lock);

        expect($result?->failure?->errorCode)->toBe('instance.lifecycle_busy')
            ->and(plain_git($this->fixture->home, 'rev-parse', 'HEAD'))->toBe($this->fixture->initialCommit)
            ->and(file_exists('/tmp/orbit-lifecycle-'.posix_geteuid().'-'.hash('sha256', $this->fixture->home).'.lock'))->toBeFalse();
    });

    it('stops a step at its timeout', function (): void {
        plain_steps($this->fixture->instance, [new DevelopmentDeployStep('hang', 'sleep 60', timeoutSeconds: 1)]);
        $started = microtime(true);

        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance);

        expect($result?->failure?->errorCode)->toBe('deployment.step_failed')
            ->and($result?->commands[0]->result->exitCode)->toBe(143)
            ->and(microtime(true) - $started)->toBeLessThan(20.0);
    });
});
