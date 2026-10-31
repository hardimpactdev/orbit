<?php

declare(strict_types=1);

use App\Models\Instance;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\LocalShellSshExecutor;

require_once __DIR__.'/../../../Support/TaskCheckRunnerFixtures.php';

beforeEach(function (): void {
    $this->directory = check_runner_directory();
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

function analysis_runner_instance(): Instance
{
    $checkout = check_runner_checkout('true');
    File::ensureDirectoryExists($checkout.'/bin');
    file_put_contents($checkout.'/bin/task-analyze', <<<'PHP'
        #!/usr/bin/env php
        <?php
        $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        if ($argc !== 1 || getenv('COMPOSER_AUTH') !== false || getenv('ORBIT_SECRET_TEST') !== false) {
            exit(70);
        }
        file_put_contents('.git/analysis-invocations', "1\n", FILE_APPEND);
        $result = ['schema' => 1, ...array_intersect_key($input, array_flip(['execution_id', 'plan_sha256', 'base', 'head', 'tree'])), 'status' => 'ready', 'diagnostics' => [], 'report' => ['schema' => 'fixture.v1', 'data' => ['all' => str_repeat('é', 6000)]]];
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        PHP
        ."\n");
    chmod($checkout.'/bin/task-analyze', 0755);
    new Process(['git', 'add', '.'], $checkout)->mustRun();
    new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'fixed entrypoint'], $checkout)->mustRun();
    new Process(['git', 'branch', '-m', 'task-8'], $checkout)->mustRun();

    return check_runner_instance($checkout);
}

it('resolves only the provisioned starting commit against the local origin main ref without fetching', function (): void {
    $instance = analysis_runner_instance();
    $runner = check_runner(new LocalShellSshExecutor);
    $head = trim(new Process(['git', 'rev-parse', 'HEAD'], $instance->checkout_path)->mustRun()->getOutput());
    new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'task starting commit'], $instance->checkout_path)->mustRun();
    $starting = trim(new Process(['git', 'rev-parse', 'HEAD'], $instance->checkout_path)->mustRun()->getOutput());
    $instance->update(['starting_commit' => $starting]);
    new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'later candidate'], $instance->checkout_path)->mustRun();
    $candidate = trim(new Process(['git', 'rev-parse', 'HEAD'], $instance->checkout_path)->mustRun()->getOutput());
    new Process(['git', 'update-ref', 'refs/remotes/origin/main', $head], $instance->checkout_path)->mustRun();
    $pinned = $runner->initialAnalysisBase($instance);
    expect($pinned)->toBe($head);
    new Process(['git', 'update-ref', 'refs/remotes/origin/main', $candidate], $instance->checkout_path)->mustRun();
    expect($runner->initialAnalysisBase($instance))->toBe($starting);
    expect($pinned)->toBe($head);
    new Process(['git', 'update-ref', '-d', 'refs/remotes/origin/main'], $instance->checkout_path)->mustRun();
    expect($runner->initialAnalysisBase($instance))->toBeNull();
});

it('leaves the initial base unavailable without a full existing starting commit', function (?string $starting): void {
    $instance = analysis_runner_instance();
    $instance->update(['starting_commit' => $starting]);
    new Process(['git', 'update-ref', 'refs/remotes/origin/main', 'HEAD'], $instance->checkout_path)->mustRun();

    expect(check_runner(new LocalShellSshExecutor)->initialAnalysisBase($instance))->toBeNull();
})->with([
    'missing' => [null],
    'revision selector' => ['HEAD'],
    'option' => ['--all'],
    'absent object' => [str_repeat('a', 40)],
]);
