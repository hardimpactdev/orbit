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

it('forwards the first interrupt and forces an uncooperative child to stop on the second', function (): void {
    $script = 'import os,signal,time; signal.signal(signal.SIGTERM,lambda *_: print("interrupted",flush=True)); '
        .'print(os.environ["TMPDIR"],flush=True); time.sleep(60)';
    $process = new Process([dirname(__DIR__, 4).'/bin/pest-plain', 'python3', '-c', $script], timeout: 10);
    $process->start();
    $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'run-'));
    $directory = trim($process->getOutput());
    $process->signal(SIGTERM);
    $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'interrupted'));
    expect($process->isRunning())->toBeTrue();
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
child_script = '''import os,pathlib,signal,time
signal.signal(signal.SIGTERM,signal.SIG_IGN)
parent = os.getppid()
pathlib.Path(os.environ['TMPDIR'],'ready').touch()
while pathlib.Path(f'/proc/{parent}').exists(): time.sleep(0.01)
print('leader gone',flush=True)
time.sleep(30)
'''
child=subprocess.Popen([sys.executable,'-c',child_script], start_new_session=True)
while not pathlib.Path(root,'ready').exists(): time.sleep(0.001)
print(root,flush=True)
time.sleep(30)
PYTHON;
    $process = new Process([dirname(__DIR__, 4).'/bin/pest-plain', 'python3', '-c', $script], timeout: 10);
    $process->start();
    $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'run-'));
    $directory = trim($process->getOutput());
    $process->signal(SIGTERM);
    $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'leader gone'));
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

    $deadline = microtime(true) + 10;
    while (is_dir($directory) && microtime(true) < $deadline) {
        usleep(10_000);
        clearstatcache(true, $directory);
    }

    expect(is_dir($directory))->toBeFalse();
})->skip(PHP_OS_FAMILY !== 'Linux', 'Parent death notification uses Linux prctl.');

it('reaps an adopted orphan while the command is still running', function (): void {
    $script = <<<'PYTHON'
import pathlib,subprocess,sys,time
parent = subprocess.run([sys.executable,'-c', 'import subprocess,sys; child=subprocess.Popen([sys.executable,"-c","import time; time.sleep(0.2)"],start_new_session=True,stdout=subprocess.DEVNULL); print(child.pid,flush=True)'],capture_output=True,text=True,check=True)
pid = int(parent.stdout)
deadline = time.monotonic()+5
while pathlib.Path(f'/proc/{pid}').exists() and time.monotonic()<deadline:
    time.sleep(0.01)
assert not pathlib.Path(f'/proc/{pid}').exists(), f'orphan {pid} was not reaped during the run'
print('reaped mid-run',flush=True)
PYTHON;
    $process = new Process([dirname(__DIR__, 4).'/bin/pest-plain', 'python3', '-c', $script], timeout: 10);
    $process->mustRun();

    expect($process->getOutput())->toBe("reaped mid-run\n");
})->skip(PHP_OS_FAMILY !== 'Linux', 'Orphan adoption uses Linux subreapers.');

it('keeps temporary fixtures outside the checkout and respects the inherited temporary root', function (): void {
    $root = sys_get_temp_dir().'/pest-root-'.bin2hex(random_bytes(6));
    mkdir($root, 0o755);
    $script = <<<'PYTHON'
import json,os,subprocess
result=subprocess.run(['git','rev-parse','--show-toplevel'],cwd=os.environ['TMPDIR'],capture_output=True)
print(json.dumps([os.environ['TMPDIR'],result.returncode]))
PYTHON;
    $process = new Process([dirname(__DIR__, 4).'/bin/pest-plain', 'python3', '-c', $script], env: ['TMPDIR' => $root]);

    try {
        $process->mustRun();
        [$directory, $status] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($directory)->toStartWith($root.'/run-')
            ->and($status)->toBe(128)
            ->and(is_dir($directory))->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('lets the command finish its interrupt cleanup before removing temporary files', function (): void {
    $script = <<<'PYTHON'
import os,signal,sys,time

def cleanup(*_):
    time.sleep(1.2)
    assert os.path.isdir(os.environ['TMPDIR'])
    print('cleanup finished',flush=True)
    sys.exit(0)

signal.signal(signal.SIGTERM,cleanup)
print(os.environ['TMPDIR'],flush=True)
time.sleep(60)
PYTHON;
    $process = new Process([dirname(__DIR__, 4).'/bin/pest-plain', 'python3', '-c', $script], timeout: 10);
    $process->start();
    $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'run-'));
    $directory = trim($process->getOutput());
    $process->signal(SIGTERM);
    $process->wait();

    expect($process->getExitCode())->toBe(143)
        ->and($process->getOutput())->toContain('cleanup finished')
        ->and(is_dir($directory))->toBeFalse();
});

it('preserves the run directory and reports when permission prevents stopping descendants', function (): void {
    $script = <<<'PYTHON'
import json,pathlib,sys
from unittest.mock import patch
sys.path.insert(0,sys.argv[1])
import test_runtime
with patch.object(test_runtime.os,'killpg',side_effect=PermissionError):
    sys.exit(test_runtime.main([sys.executable,'-c', 'import json,os; print(json.dumps(os.environ["TMPDIR"]))']))
PYTHON;
    $process = new Process(['python3', '-c', $script, dirname(__DIR__, 4).'/tools'], timeout: 10);
    $process->mustRun();
    $directory = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);

    try {
        expect(is_dir($directory))->toBeTrue()
            ->and($process->getErrorOutput())->toContain('preserved for inspection')
            ->not->toContain('Traceback');
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
});

it('lets a worker finish interrupt cleanup after its leader exits', function (int $signal, bool $holdsOutput, bool $detached): void {
    if ($detached && PHP_OS_FAMILY !== 'Linux') {
        $this->markTestSkipped('Orphan adoption uses Linux subreapers.');
    }

    $markers = sys_get_temp_dir().'/pest-worker-'.bin2hex(random_bytes(6));
    mkdir($markers);
    $script = <<<'PYTHON'
import os,pathlib,signal,subprocess,sys,time
markers = pathlib.Path(sys.argv[1])
worker = '''import os,pathlib,signal,sys,time
markers = pathlib.Path(sys.argv[1])
def cleanup(*_):
    (markers/'started').touch()
    time.sleep(1.4)
    assert pathlib.Path(os.environ['TMPDIR']).is_dir()
    (markers/'finished').touch()
    sys.exit(0)
signal.signal(signal.SIGTERM,cleanup)
signal.signal(signal.SIGINT,cleanup)
(markers/'ready').touch()
time.sleep(60)
'''
detached = sys.argv[3]=='detached'
child = subprocess.Popen([sys.executable,'-c',worker,str(markers)],stdout=None if sys.argv[2]=='held' else subprocess.DEVNULL,start_new_session=detached)
def leader_cleanup(sig,_):
    if detached: os.kill(child.pid,sig)
    sys.exit(0)
signal.signal(signal.SIGTERM,leader_cleanup)
signal.signal(signal.SIGINT,leader_cleanup)
while not (markers/'ready').exists(): time.sleep(0.01)
print(os.environ['TMPDIR'],flush=True)
time.sleep(60)
PYTHON;
    $process = new Process([dirname(__DIR__, 4).'/bin/pest-plain', 'python3', '-c', $script, $markers, $holdsOutput ? 'held' : 'closed', $detached ? 'detached' : 'group'], timeout: 10);

    try {
        $process->start();
        $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'run-'));
        $directory = trim($process->getOutput());
        $process->signal($signal);
        $process->wait();

        expect($process->getExitCode())->toBe(128 + $signal)
            ->and(is_file($markers.'/started'))->toBeTrue()
            ->and(is_file($markers.'/finished'))->toBeTrue()
            ->and(is_dir($directory))->toBeFalse();
    } finally {
        $process->stop();
        new Filesystem()->deleteDirectory($markers);
    }
})->with([
    'TERM with inherited output' => [SIGTERM, true, false],
    'TERM with closed output' => [SIGTERM, false, false],
    'INT with inherited output' => [SIGINT, true, false],
    'INT with closed output' => [SIGINT, false, false],
    'adopted child with closed output' => [SIGTERM, false, true],
]);

it('bounds the interrupt grace period for a command that never finishes cleanup', function (): void {
    $script = <<<'PYTHON'
import sys
from unittest.mock import patch
sys.path.insert(0,sys.argv[1])
import test_runtime
with patch.object(test_runtime,'INTERRUPT_GRACE_SECONDS',0.2):
    sys.exit(test_runtime.main([sys.executable,'-c','import os,signal,time; signal.signal(signal.SIGTERM,signal.SIG_IGN); print(os.environ["TMPDIR"],flush=True); time.sleep(60)']))
PYTHON;
    $process = new Process(['python3', '-c', $script, dirname(__DIR__, 4).'/tools'], timeout: 10);
    $process->start();
    $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'run-'));
    $directory = trim($process->getOutput());
    $process->signal(SIGTERM);
    $process->wait();

    expect($process->getExitCode())->toBe(143)
        ->and(is_dir($directory))->toBeFalse();
});

it('forwards an interrupt received before the command spawn returns', function (): void {
    $script = <<<'PYTHON'
import os,signal,sys
from unittest.mock import patch
sys.path.insert(0,sys.argv[1])
import test_runtime
spawn = test_runtime.subprocess.Popen
def interrupted_spawn(*args,**kwargs):
    process = spawn(*args,**kwargs)
    os.kill(os.getpid(),signal.SIGTERM)
    return process
with patch.object(test_runtime.subprocess,'Popen',side_effect=interrupted_spawn):
    sys.exit(test_runtime.main([sys.executable,'-c','import time; time.sleep(1.4); print("ran to the end")']))
PYTHON;
    $process = new Process(['python3', '-c', $script, dirname(__DIR__, 4).'/tools'], timeout: 10);
    $process->run();

    expect($process->getExitCode())->toBe(143)
        ->and($process->getOutput())->toBe('');
});
