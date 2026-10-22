<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\StandardLibraryPython;

it('runs real standard-library Python without site startup while preserving command boundaries', function (): void {
    $home = sys_get_temp_dir().'/orbit-stdlib-proof-'.bin2hex(random_bytes(12));
    mkdir($home, 0o700);
    file_put_contents($home.'/sitecustomize.py', 'from pathlib import Path; Path(__file__).with_suffix(".ran").touch()');
    file_put_contents($home.'/fixture_module.py', 'value = "private module"');
    $bytes = str_repeat(implode('', array_map(chr(...), range(0, 255))), 257)."\0\n\n";
    $path = (string) getenv('PATH');
    $environment = ['PATH' => StandardLibraryPython::path($path), 'PYTHONPATH' => $home, 'FIXTURE_VALUE' => "spaces and\nlines"];
    $program = <<<'PYTHON'
import json, os, sys
import fixture_module
sys.stdout.buffer.write(sys.stdin.buffer.read())
sys.stderr.write(json.dumps({"arguments": sys.argv[1:], "value": os.environ["FIXTURE_VALUE"], "mode": sys.flags.no_site, "module": fixture_module.value}))
sys.exit(7)
PYTHON;
    $process = new Process(['/usr/bin/env', 'python3', '-c', $program, 'space argument', '', "line\nbreak", '☄'], env: $environment, input: $bytes);
    $process->run();

    expect($process->getExitCode())->toBe(7)
        ->and($process->getOutput())->toBe($bytes)
        ->and(json_decode($process->getErrorOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'arguments' => ['space argument', '', "line\nbreak", '☄'],
            'value' => "spaces and\nlines",
            'mode' => 1,
            'module' => 'private module',
        ])
        ->and(is_file($home.'/sitecustomize.ran'))->toBeFalse()
        ->and(StandardLibraryPython::path($path))->toBe($environment['PATH'])
        ->and(getenv('PATH'))->toBe($path);

    $native = new Process(['python3', '-c', 'pass'], env: ['PYTHONPATH' => $home]);
    $native->mustRun();
    expect(is_file($home.'/sitecustomize.ran'))->toBeTrue();
});

it('keeps isolated mode on explicit standard-library Python invocations', function (): void {
    $process = new Process([
        StandardLibraryPython::executable(), '-I', '-c',
        'import json, os, sys; print(json.dumps([sys.flags.isolated, sys.flags.no_site, sys.argv[1:], os.environ["FIXTURE_VALUE"]]))',
        '', "spaces and\nlines", '☄',
    ], env: ['FIXTURE_VALUE' => 'private environment']);
    $process->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        1, 1, ['', "spaces and\nlines", '☄'], 'private environment',
    ])->and($process->getErrorOutput())->toBe('');
});
