<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('owns temporary files before PHP starts and removes them even when the command fails', function (int $exit): void {
    $script = 'import json,os,pathlib,sys; root=os.environ["TMPDIR"]; '
        .'pathlib.Path(root,"123.php").write_text("fixture"); '
        .'print(json.dumps([root,os.environ["TMP"],os.environ["TEMP"]])); sys.exit('.$exit.')';
    $process = new Process([dirname(__DIR__, 4).'/bin/pest-plain', 'python3', '-c', $script]);
    $process->run();
    $paths = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($process->getExitCode())->toBe($exit)
        ->and($paths[0])->not->toBe(sys_get_temp_dir())
        ->and($paths)->toBe(array_fill(0, 3, $paths[0]))
        ->and(is_dir($paths[0]))->toBeFalse();
})->with([0, 7]);

it('stops orphaned children before removing their files and preserves unrelated runs', function (): void {
    $root = sys_get_temp_dir().'/pest-plain-'.bin2hex(random_bytes(6));
    mkdir($root);
    file_put_contents($root.'/keep', 'another run');
    $script = <<<'PY'
import json,os,pathlib,subprocess,sys
root=os.environ['TMPDIR']
child=subprocess.Popen([sys.executable,'-c', 'import os,time; f=open(os.environ["TMPDIR"]+"/held","w"); print("ready",flush=True); time.sleep(60)'], stdout=subprocess.PIPE, start_new_session=True)
assert child.stdout.readline().strip()==b'ready'
print(json.dumps([root,child.pid]),flush=True)
PY;
    $process = new Process([dirname(__DIR__, 4).'/bin/pest-plain', 'python3', '-c', $script], env: ['TMPDIR' => $root], timeout: 10);

    try {
        $process->mustRun();
        [$directory, $pid] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect(is_dir($directory))->toBeFalse()
            ->and(is_dir('/proc/'.$pid))->toBeFalse()
            ->and(file_get_contents($root.'/keep'))->toBe('another run');
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->skip(PHP_OS_FAMILY !== 'Linux', 'Orphan adoption uses Linux subreapers.');

it('forwards termination and waits for an uncooperative child before cleanup', function (): void {
    $script = 'import os,signal,time; signal.signal(signal.SIGTERM,signal.SIG_IGN); '
        .'print(os.environ["TMPDIR"],flush=True); time.sleep(60)';
    $process = new Process([dirname(__DIR__, 4).'/bin/pest-plain', 'python3', '-c', $script], timeout: 10);
    $process->start();
    $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'run-'));
    $directory = trim($process->getOutput());
    $process->signal(SIGTERM);
    $process->wait();

    expect($process->getExitCode())->toBe(143)
        ->and(is_dir($directory))->toBeFalse();
});

it('strips ANSI sequences split across writes and keeps the test summary', function (): void {
    $script = 'import os,time; os.write(1,b"\\x1b["); time.sleep(0.1); '
        .'os.write(1,b"1A  Tests: 1 passed\\n"); os.write(2,b"stderr\\n")';
    $process = new Process([dirname(__DIR__, 4).'/bin/pest-plain', 'python3', '-c', $script]);
    $process->mustRun();

    expect($process->getOutput())->toBe("  Tests: 1 passed\nstderr\n");
});

it('cleans up an orphan that keeps writing after its parent exits', function (): void {
    $script = <<<'PYTHON'
import json,os,pathlib,subprocess,sys,time
root=os.environ['TMPDIR']
print(json.dumps(root),flush=True)
child_script = """import os,pathlib,time
pathlib.Path(os.environ["TMPDIR"],"ready").touch()
end=time.monotonic()+30
while time.monotonic()<end:
    print("log",flush=True)
    time.sleep(0.001)
"""
child=subprocess.Popen([sys.executable,'-c',child_script], start_new_session=True)
while not pathlib.Path(root,'ready').exists(): time.sleep(0.001)
PYTHON;
    $process = new Process([dirname(__DIR__, 4).'/bin/pest-plain', 'python3', '-c', $script], timeout: 10);
    $process->mustRun();
    $directory = json_decode(strtok($process->getOutput(), "\n"), true, flags: JSON_THROW_ON_ERROR);

    expect(is_dir($directory))->toBeFalse();
})->skip(PHP_OS_FAMILY !== 'Linux', 'Orphan adoption uses Linux subreapers.');

it('preserves the termination exit status when a detached child holds stdout after its parent exits', function (): void {
    $script = <<<'PYTHON'
import json,os,pathlib,subprocess,sys,time
root=os.environ['TMPDIR']
child=subprocess.Popen([sys.executable,'-c', 'import os,pathlib,signal,time; signal.signal(signal.SIGTERM,signal.SIG_IGN); pathlib.Path(os.environ["TMPDIR"],"ready").touch(); time.sleep(30)'], start_new_session=True)
while not pathlib.Path(root,'ready').exists(): time.sleep(0.001)
print(root,flush=True)
time.sleep(30)
PYTHON;
    $process = new Process([dirname(__DIR__, 4).'/bin/pest-plain', 'python3', '-c', $script], timeout: 10);
    $process->start();
    $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'run-'));
    $directory = trim($process->getOutput());
    $process->signal(SIGTERM);
    $process->wait();

    expect($process->getExitCode())->toBe(143)
        ->and(is_dir($directory))->toBeFalse();
})->skip(PHP_OS_FAMILY !== 'Linux', 'Orphan adoption uses Linux subreapers.');

it('stops the run when the launcher parent dies', function (): void {
    $script = <<<'PYTHON'
import subprocess,sys,time
subprocess.Popen([sys.argv[1],sys.executable,'-c', 'import os,time; print(os.environ["TMPDIR"],flush=True); time.sleep(60)'])
time.sleep(60)
PYTHON;
    $process = new Process(['python3', '-c', $script, dirname(__DIR__, 4).'/bin/pest-plain'], timeout: 10);
    $process->start();
    $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'run-'));
    $directory = trim($process->getOutput());
    $process->signal(SIGTERM);
    $process->wait();

    expect(is_dir($directory))->toBeFalse();
})->skip(PHP_OS_FAMILY !== 'Linux', 'Parent death notification uses Linux prctl.');
