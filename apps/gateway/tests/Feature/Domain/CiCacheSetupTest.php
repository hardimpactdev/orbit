<?php

declare(strict_types=1);

use App\Domain\Projects\GatewaySetupSteps;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\LifecycleAgent;
use Tests\Support\LocalAgentBinary;

/**
 * Runs the agent's @orbit/ci-caches program in the checkout, as an Instance setup step runs it.
 *
 * @return array{exit: int, stdout: string}
 */
function ci_setup_run(string $checkout): array
{
    $node = new Node(['name' => 'ci', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'wireguard_ip' => '127.0.0.1']);
    $answer = LocalAgentBinary::localRunner(sys_get_temp_dir().'/orbit-ci-cache-agent-'.bin2hex(random_bytes(6)))->run($node, 'instances.run-lifecycle-step', [
        'checkout' => $checkout, 'program' => 'import_ci_caches', 'step' => 'ci-caches', 'timeout_seconds' => 60, 'environment' => ['ORBIT_SEED_PATH' => ''],
    ]);
    $stdout = implode('', array_map(static fn (array $chunk): string => $chunk['stream'] === 'stdout' ? $chunk['value'] : '', $answer['output']));

    return ['exit' => $answer['exit_code'], 'stdout' => $stdout];
}

function ci_setup_git(string $checkout, array $arguments): string
{
    return trim((new Process(['git', '-C', $checkout, ...$arguments]))->mustRun()->getOutput());
}

describe('CI main cache setup', function (): void {
    beforeEach(function (): void {
        $this->checkout = sys_get_temp_dir().'/orbit-ci-cache-test-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->checkout.'/bin');
        file_put_contents($this->checkout.'/bin/ci-cache-ref', <<<'PYTHON'
            import pathlib, sys
            pathlib.Path('imports.log').write_text(' '.join(sys.argv[1:]))
            code = pathlib.Path('import-exit')
            sys.exit(int(code.read_text()) if code.exists() else 0)
            PYTHON);
        ci_setup_git($this->checkout, ['init', '--quiet', '-b', 'main']);
        ci_setup_git($this->checkout, ['-c', 'user.name=CI', '-c', 'user.email=ci@example.test', 'add', '.']);
        ci_setup_git($this->checkout, ['-c', 'user.name=CI', '-c', 'user.email=ci@example.test', 'commit', '--quiet', '-m', 'baseline']);
        $this->baseline = ci_setup_git($this->checkout, ['rev-parse', 'HEAD']);
        ci_setup_git($this->checkout, ['update-ref', 'refs/remotes/origin/main', $this->baseline]);
        ci_setup_git($this->checkout, ['symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/main']);
        ci_setup_git($this->checkout, ['update-ref', 'refs/worktree/orbit/ci-baseline', $this->baseline]);
    });
    afterEach(function (): void {
        File::deleteDirectory($this->checkout);
    });

    it('imports the selected code SHA without an artifact payload or credential', function (bool $task): void {
        if ($task) {
            ci_setup_git($this->checkout, ['checkout', '--quiet', '-b', 'task-1574']);
            ci_setup_git($this->checkout, ['-c', 'user.name=CI', '-c', 'user.email=ci@example.test', 'commit', '--allow-empty', '--quiet', '-m', 'task change']);
        }
        $script = GatewaySetupSteps::script(LifecycleStep::ImportCiCaches);
        $process = ci_setup_run($this->checkout);

        expect($process['exit'])->toBe(0)
            ->and(file_get_contents($this->checkout.'/imports.log'))->toBe('import --commit '.$this->baseline)
            ->and(strlen($script))->toBeLessThan(4096)
            ->and($script)->not->toContain('base64', '.zip', 'GITHUB_TOKEN');
    })->with(['main' => false, 'recorded task baseline' => true]);

    it('keeps the task baseline private to each linked worktree', function (): void {
        $linked = $this->checkout.'/linked';
        ci_setup_git($this->checkout, ['worktree', 'add', '--quiet', '-b', 'task-linked', $linked, $this->baseline]);
        ci_setup_git($linked, ['update-ref', 'refs/worktree/orbit/ci-baseline', $this->baseline]);
        ci_setup_git($this->checkout, ['-c', 'user.name=CI', '-c', 'user.email=ci@example.test', 'commit', '--allow-empty', '--quiet', '-m', 'new main']);
        $next = ci_setup_git($this->checkout, ['rev-parse', 'HEAD']);
        ci_setup_git($this->checkout, ['update-ref', 'refs/worktree/orbit/ci-baseline', $next]);
        $process = ci_setup_run($linked);

        expect($process['exit'])->toBe(0)
            ->and(file_get_contents($linked.'/imports.log'))->toBe('import --commit '.$this->baseline)
            ->and(ci_setup_git($this->checkout, ['rev-parse', 'refs/worktree/orbit/ci-baseline']))->toBe($next);
    });

    it('fails the step on a write failure or missing required tooling', function (bool $missing): void {
        $missing ? unlink($this->checkout.'/bin/ci-cache-ref') : file_put_contents($this->checkout.'/import-exit', '1');
        expect(ci_setup_run($this->checkout)['exit'])->toBe(1);
    })->with(['an import failure' => false, 'no bin/ci-cache-ref' => true]);

    it('runs cold without a recorded task baseline rather than choosing the newest main ref', function (): void {
        ci_setup_git($this->checkout, ['checkout', '--quiet', '-b', 'task-1574']);
        ci_setup_git($this->checkout, ['-c', 'user.name=CI', '-c', 'user.email=ci@example.test', 'commit', '--allow-empty', '--quiet', '-m', 'task change']);
        ci_setup_git($this->checkout, ['update-ref', '-d', 'refs/worktree/orbit/ci-baseline']);
        $process = ci_setup_run($this->checkout);

        expect($process['exit'])->toBe(0)
            ->and($process['stdout'])->toContain('cold: no recorded code baseline')
            ->and(file_exists($this->checkout.'/imports.log'))->toBeFalse();
    });

    it('imports the HEAD commit of another branch at a main commit, not a newer main', function (): void {
        ci_setup_git($this->checkout, ['checkout', '--quiet', '-b', 't3/agent']);
        ci_setup_git($this->checkout, ['update-ref', '-d', 'refs/worktree/orbit/ci-baseline']);
        $newer = ci_setup_git($this->checkout, ['-c', 'user.name=CI', '-c', 'user.email=ci@example.test', 'commit-tree', 'HEAD^{tree}', '-p', 'HEAD', '-m', 'new main']);
        ci_setup_git($this->checkout, ['update-ref', 'refs/remotes/origin/main', $newer]);
        expect(ci_setup_run($this->checkout)['exit'])->toBe(0)
            ->and(file_get_contents($this->checkout.'/imports.log'))->toBe('import --commit '.$this->baseline);
    });

    it('runs as a Gateway-owned Instance setup step and only in setup', function (): void {
        $project = Project::query()->create(['name' => 'CI', 'slug' => 'ci', 'repository_url' => 'https://github.com/acme/orbit.git']);
        $node = Node::query()->create(['name' => 'ci', 'wireguard_ip' => '192.0.2.9', 'public_ssh_host' => '192.0.2.9']);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'ci', 'checkout_path' => $this->checkout, 'status' => 'active']);
        $store = new ProjectLifecycleStepStore;
        $store->create($project, LifecyclePhase::Setup, new LifecycleStep('ci-caches', LifecycleStep::ImportCiCaches, 60), null, null);
        $store->create($project, LifecyclePhase::Teardown, new LifecycleStep('ci-caches', LifecycleStep::ImportCiCaches, 60), null, null);
        $transport = new LifecycleAgent(local: true);

        expect($transport->runner()->run($instance, LifecyclePhase::Setup))->toBeTrue()
            ->and(file_get_contents($this->checkout.'/imports.log'))->toBe('import --commit '.$this->baseline)
            ->and(fn () => $transport->runner()->run($instance, LifecyclePhase::Teardown))->toThrow(ResourceOperationException::class, 'setup-only')
            ->and(GatewaySetupSteps::owns(LifecycleStep::ImportCiCaches))->toBeTrue()
            ->and(GatewaySetupSteps::owns('@orbit/tia-baseline'))->toBeFalse();
    });
});
