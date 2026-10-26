<?php

declare(strict_types=1);

use Tests\Support\PythonPrograms;

it('isolates each real Python process and keeps its filesystem effects', function (): void {
    $host = new PythonPrograms;
    $path = sys_get_temp_dir().'/orbit-fork-proof-'.bin2hex(random_bytes(8));
    $bootstrap = file_get_contents(__DIR__.'/../../../resources/remote/bootstrap.py');
    $source = <<<'PYTHON'
        import atexit, builtins, json, os, sys
        payload = json.load(sys.stdin)
        print(json.dumps({'pid': os.getpid(), 'group': os.getpgrp(), 'cwd': os.getcwd(),
                          'mutated': hasattr(builtins, 'orbit_test_mutation'),
                          'environment': os.environ.get('ORBIT_FORK_PROOF'),
                          'previous': open(payload['path']).read() if os.path.exists(payload['path']) else None}))
        builtins.orbit_test_mutation = True
        os.environ['ORBIT_FORK_PROOF'] = 'mutated'
        os.chdir('/')
        atexit.register(lambda: open(payload['path'], 'w').write('exit cleanup ran'))
        PYTHON;
    try {
        $input = $source."\0".json_encode(['path' => $path], JSON_THROW_ON_ERROR);
        $first = $host->run($bootstrap, 'isolation-proof', $input, ['ORBIT_FORK_PROOF' => 'first']);
        $second = $host->run($bootstrap, 'isolation-proof', $input, ['ORBIT_FORK_PROOF' => 'second']);
        $a = json_decode($first->stdout, true, flags: JSON_THROW_ON_ERROR);
        $b = json_decode($second->stdout, true, flags: JSON_THROW_ON_ERROR);

        expect($first->exitCode)->toBe(0)->and($second->exitCode)->toBe(0)
            ->and($a['pid'])->not->toBe($b['pid'])
            ->and($a['group'])->toBe($a['pid'])->and($b['group'])->toBe($b['pid'])
            ->and($a['cwd'])->toBe(getcwd())->and($b['cwd'])->toBe(getcwd())
            ->and($a['mutated'])->toBeFalse()->and($b['mutated'])->toBeFalse()
            ->and($a['environment'])->toBe('first')->and($b['environment'])->toBe('second')
            ->and($a['previous'])->toBeNull()->and($b['previous'])->toBe('exit cleanup ran');
    } finally {
        @unlink($path);
    }
});

it('captures stdout stderr and nonzero exits without poisoning the next invocation', function (): void {
    $host = new PythonPrograms;
    $bootstrap = file_get_contents(__DIR__.'/../../../resources/remote/bootstrap.py');
    $failed = $host->run($bootstrap, 'failure-proof', "import sys\nprint('partial output')\nprint('diagnostic', file=sys.stderr)\nsys.exit(7)\0{}");
    $passed = $host->run($bootstrap, 'success-proof', "print('recovered')\0{}");

    expect($failed->exitCode)->toBe(7)->and($failed->stdout)->toBe("partial output\n")
        ->and($failed->stderr)->toBe("diagnostic\n")->and($failed->truncated)->toBeFalse()
        ->and($passed->exitCode)->toBe(0)->and($passed->stdout)->toBe("recovered\n");
});

it('bounds a hung real program and can execute another program afterwards', function (): void {
    $host = new PythonPrograms(timeoutSeconds: 0.5);
    $bootstrap = file_get_contents(__DIR__.'/../../../resources/remote/bootstrap.py');
    $hung = $host->run($bootstrap, 'timeout-proof', "import time\nprint('started', flush=True)\ntime.sleep(30)\0{}");
    $passed = $host->run($bootstrap, 'success-proof', "print('recovered')\0{}");

    expect($hung->exitCode)->toBe(124)
        ->and($hung->stdout)->toBe("started\n")
        ->and($hung->stderr)->toContain('timed out')
        ->and($passed->exitCode)->toBe(0)->and($passed->stdout)->toBe("recovered\n");
});

it('kills a hung program and its children before returning the timeout', function (): void {
    $programs = new PythonPrograms(timeoutSeconds: 0.5);
    $source = "import subprocess, time\nchild = subprocess.Popen(['sleep', '30'])\nprint(child.pid, flush=True)\ntime.sleep(30)";

    $result = $programs->runCommand($source, []);

    expect($result->exitCode)->toBe(124);
    $child = (int) trim($result->stdout);
    expect($child)->toBeGreaterThan(1);
    $deadline = microtime(true) + 2;
    do {
        $stat = '/proc/'.$child.'/stat';
        $state = is_file($stat) ? file_get_contents($stat) : false;
        if ($state === false || preg_match('/\) Z /', $state) === 1) {
            break;
        }
        usleep(10_000);
    } while (microtime(true) < $deadline);
    expect($state === false || preg_match('/\) Z /', $state) === 1)->toBeTrue();
});
