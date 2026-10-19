<?php

declare(strict_types=1);

use Tests\Support\ForkedPythonPrograms;

it('shares compiled input but isolates each real Python process and keeps its filesystem effects', function (): void {
    $host = new ForkedPythonPrograms;
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
        $host->close();
        @unlink($path);
    }
});

it('captures stdout stderr and nonzero exits without poisoning the next invocation', function (): void {
    $host = new ForkedPythonPrograms;
    $bootstrap = file_get_contents(__DIR__.'/../../../resources/remote/bootstrap.py');
    try {
        $failed = $host->run($bootstrap, 'failure-proof', "import sys\nprint('partial output')\nprint('diagnostic', file=sys.stderr)\nsys.exit(7)\0{}");
        $passed = $host->run($bootstrap, 'success-proof', "print('recovered')\0{}");

        expect($failed->exitCode)->toBe(7)->and($failed->stdout)->toBe("partial output\n")
            ->and($failed->stderr)->toBe("diagnostic\n")->and($failed->truncated)->toBeFalse()
            ->and($passed->exitCode)->toBe(0)->and($passed->stdout)->toBe("recovered\n");
    } finally {
        $host->close();
        $host->close();
    }
});

it('bounds a hung real program and can execute another program afterwards', function (): void {
    $host = new ForkedPythonPrograms(timeoutSeconds: 1);
    $bootstrap = file_get_contents(__DIR__.'/../../../resources/remote/bootstrap.py');
    try {
        $hung = $host->run($bootstrap, 'timeout-proof', "import time\ntime.sleep(30)\0{}");
        $passed = $host->run($bootstrap, 'success-proof', "print('recovered')\0{}");

        expect($hung->exitCode)->toBe(124)
            ->and($hung->stderr)->toContain('timed out')
            ->and($passed->exitCode)->toBe(0)->and($passed->stdout)->toBe("recovered\n");
    } finally {
        $host->close();
    }
});
