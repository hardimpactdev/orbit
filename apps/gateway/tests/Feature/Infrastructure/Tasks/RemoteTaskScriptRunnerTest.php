<?php

declare(strict_types=1);

use App\Infrastructure\Tasks\TaskUnitProgram;
use App\Models\Instance;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\ForkedPythonPrograms;
use Tests\Support\LocalShellSshExecutor;

require_once __DIR__.'/../../../Support/TaskCheckRunnerFixtures.php';

beforeEach(function (): void {
    $this->directory = check_runner_directory();
});
afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

function script_runner_instance(): Instance
{
    $checkout = check_runner_checkout('true');
    File::ensureDirectoryExists($checkout.'/scripts');
    file_put_contents($checkout.'/scripts/dependencies', <<<'PHP_SCRIPT'
#!/usr/bin/env php
<?php
if (getenv('COMPOSER_AUTH') !== false || getenv('ORBIT_SECRET_TEST') !== false) { exit(70); }
file_put_contents('.git/script-invocations', "1\n", FILE_APPEND);
file_put_contents('updated.lock', json_encode(array_slice($argv, 1)));
fwrite(STDOUT, str_repeat("output line\n", 10000));
fwrite(STDERR, "last diagnostic\n");
exit(23);
PHP_SCRIPT
    );
    chmod($checkout.'/scripts/dependencies', 0755);
    new Process(['git', 'add', '.'], $checkout)->mustRun();
    new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'tracked script'], $checkout)->mustRun();
    new Process(['git', 'branch', '-m', 'task-8'], $checkout)->mustRun();

    return check_runner_instance($checkout);
}

it('executes literal arguments once through the owned receipt and bounds combined output', function (): void {
    $instance = script_runner_instance();
    $runner = check_runner(new LocalShellSshExecutor);
    $before = $runner->inspectScript($instance, 'scripts/dependencies');
    $id = bin2hex(random_bytes(16));
    $input = [...$before, 'execution_id' => $id, 'script' => 'scripts/dependencies', 'args' => ['routine', '$(touch injected); * literal']];
    $script = $instance->checkout_path.'/.git/orbit/check';
    $bootstrap = <<<'PYTHON'
import io, json, os, sys
source, options = sys.stdin.buffer.read().split(b'\0', 1)
options = json.loads(options)
scope = {'__name__': 'check', '__file__': options['script']}
exec(compile(source, sys.argv[1], 'exec'), scope)
request = options['input']
os.chdir(options['checkout'])
lock, path, _ = scope['start_record'](request['execution_id'])
scope['write_start_record'](path, {'run_id': request['execution_id'], 'state': 'starting', 'starter_pid': os.getpid(),
    'starter_started': scope['process_started'](os.getpid()), 'boot': open('/proc/sys/kernel/random/boot_id').read().strip()})
lock.close()
sys.stdin = io.TextIOWrapper(io.BytesIO(json.dumps(request).encode()))
arguments = ['run-owned', request['execution_id'], options['checkout'], request['head'], request['tree'], '-', '-', '', 'script']
scope['main'](arguments)
scope['main'](arguments)
with open(scope['RESULT']) as result:
    print(result.read())
PYTHON;
    $programs = new ForkedPythonPrograms;
    try {
        $capture = $programs->run($bootstrap, 'script-owned-profile', TaskUnitProgram::read('tasks/check')."\0".json_encode([
            'script' => $script, 'checkout' => $instance->checkout_path, 'input' => $input,
        ], JSON_THROW_ON_ERROR), ['ORBIT_SECRET_TEST' => 'never-inherit', 'COMPOSER_AUTH' => 'never-inherit']);
        expect($capture->exitCode)->toBe(0, $capture->stderr);
        $result = json_decode($capture->stdout, true, flags: JSON_THROW_ON_ERROR);
        expect($result['exit_code'])->toBe(23)->and($result['output_bytes'])->toBe(120016)
            ->and($result['tree_after'])->not->toBe($before['tree']);
        expect(file_get_contents($instance->checkout_path.'/updated.lock'))->toBe('["routine","$(touch injected); * literal"]');
        expect(file_exists($instance->checkout_path.'/injected'))->toBeFalse();
        expect(file_get_contents($instance->checkout_path.'/.git/script-invocations'))->toBe("1\n");
        $log = file_get_contents(dirname($script).'/check-starts/'.$id.'.log');
        expect(strlen($log))->toBeLessThanOrEqual(102400)->and($log)->toEndWith("last diagnostic\n");
        $recovered = $runner->recoverStart($instance, $id);
        expect($recovered->runId)->toBe($id)->and($runner->readScript($instance, $recovered)['state'])->toBe('running');
    } finally {
        $programs->close();
    }
});

it('refuses altered, untracked, nonexecutable and symlinked repository scripts', function (string $mode): void {
    $instance = script_runner_instance();
    $path = $instance->checkout_path.'/scripts/dependencies';
    if ($mode === 'altered') {
        file_put_contents($path, "\n# changed\n", FILE_APPEND);
    }
    if ($mode === 'untracked') {
        rename($path, $path.'-new');
        $path .= '-new';
    }
    if ($mode === 'nonexecutable') {
        chmod($path, 0644);
    }
    if ($mode === 'symlink') {
        unlink($path);
        symlink('/bin/true', $path);
    }
    if ($mode === 'parent symlink') {
        rename(dirname($path), dirname($path).'-real');
        symlink('scripts-real', dirname($path));
    }

    expect(check_runner(new LocalShellSshExecutor)->inspectScript($instance, substr($path, strlen($instance->checkout_path) + 1)))
        ->toBe(['unavailable' => true]);
    expect(file_exists($instance->checkout_path.'/.git/script-invocations'))->toBeFalse();
})->with(['altered', 'untracked', 'nonexecutable', 'symlink', 'parent symlink']);

it('removes the allocated script TMPDIR and its contents on every completed exit', function (int $exitCode): void {
    $instance = script_runner_instance();
    $checkout = $instance->checkout_path;
    file_put_contents($checkout.'/scripts/dependencies', <<<'SCRIPT'
#!/bin/sh
printf artifact > "$TMPDIR/artifact"
exit "$1"
SCRIPT
    );
    new Process(['git', 'add', 'scripts/dependencies'], $checkout)->mustRun();
    new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'temporary artifact'], $checkout)->mustRun();
    $runner = check_runner(new LocalShellSshExecutor);
    $input = [...$runner->inspectScript($instance, 'scripts/dependencies'), 'script' => 'scripts/dependencies', 'args' => [(string) $exitCode]];
    $allocate = new Process(['python3', resource_path('tasks/metadata'), $checkout, 'tmpdir']);
    $allocate->setInput('{}');
    $tmpdir = trim($allocate->mustRun()->getOutput());
    $script = $checkout.'/.git/orbit/check';
    $programs = new ForkedPythonPrograms;
    try {
        $capture = $programs->run(<<<'PYTHON'
import json, os, sys
source, options = sys.stdin.buffer.read().split(b'\0', 1)
options = json.loads(options)
scope = {'__name__': 'check', '__file__': options['script']}
exec(compile(source, sys.argv[1], 'exec'), scope)
request = options['input']
scope['run_script'](options['checkout'], request['head'], request['tree'], json.dumps(request))
with open(scope['RESULT']) as result:
    output = json.load(result)
output['artifact'] = open(os.path.join(os.environ['TMPDIR'], 'artifact')).read()
output['recorded_tmpdir'] = open(scope['TMPDIR_RECORD']).read() if os.path.isfile(scope['TMPDIR_RECORD']) else None
print(json.dumps(output))
PYTHON, 'script-tmpdir-cleanup', TaskUnitProgram::read('tasks/check')."\0".json_encode([
            'script' => $script, 'checkout' => $checkout, 'input' => $input,
        ], JSON_THROW_ON_ERROR), ['TMPDIR' => $tmpdir]);

        expect($capture->exitCode)->toBe(0, $capture->stderr);
        $result = json_decode($capture->stdout, true, flags: JSON_THROW_ON_ERROR);
        expect($result['exit_code'])->toBe($exitCode)
            ->and($result['artifact'])->toBe('artifact')
            ->and(is_dir($tmpdir))->toBeFalse()
            ->and(file_exists($tmpdir.'/artifact'))->toBeFalse()
            ->and($result['recorded_tmpdir'])->toBe($tmpdir)
            ->and(file_exists(dirname($script).'/check-tmpdir'))->toBeFalse();
    } finally {
        $programs->close();
        File::deleteDirectory($tmpdir);
    }
})->with(['success' => 0, 'failure' => 23]);
