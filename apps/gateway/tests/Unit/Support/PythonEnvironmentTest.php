<?php

declare(strict_types=1);

use Tests\Support\PythonPrograms;

it('uses the current caller environment and temporary directory for every fresh child', function (): void {
    $name = 'ORBIT_PYTHON_CHILD_'.bin2hex(random_bytes(8))."\xff";
    $directory = sys_get_temp_dir().'/orbit-python-current-env-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $originalTmpdir = getenv('TMPDIR');
    putenv($name.'=host startup');
    $host = new PythonPrograms;
    $source = <<<'PYTHON'
        import base64, json, os, sys, tempfile
        name = base64.b64decode(sys.argv[1])
        value = os.environb.get(name)
        print(json.dumps({'value': None if value is None else base64.b64encode(value).decode(), 'directory': tempfile.gettempdir()}))
        os.environb[name] = b'child mutation'
        PYTHON;
    try {
        $value = "current caller\xff\n☄";
        putenv($name.'='.$value);
        putenv('TMPDIR='.$directory);
        $first = $host->runCommand($source, [base64_encode($name)]);
        putenv($name);
        $removed = $host->runCommand($source, [base64_encode($name)]);
        putenv($name.'=current again');
        $explicit = $host->runCommand($source, [base64_encode($name)], environment: [$name => false]);

        expect($first->exitCode)->toBe(0)->and($first->stderr)->toBe('')
            ->and(json_decode($first->stdout, true, flags: JSON_THROW_ON_ERROR))->toBe(['value' => base64_encode($value), 'directory' => $directory])
            ->and($removed->exitCode)->toBe(0)->and($removed->stderr)->toBe('')
            ->and(json_decode($removed->stdout, true, flags: JSON_THROW_ON_ERROR))->toBe(['value' => null, 'directory' => $directory])
            ->and($explicit->exitCode)->toBe(0)->and($explicit->stderr)->toBe('')
            ->and(json_decode($explicit->stdout, true, flags: JSON_THROW_ON_ERROR))->toBe(['value' => null, 'directory' => $directory])
            ->and(getenv($name))->toBe('current again')
            ->and(getenv('TMPDIR'))->toBe($directory);
    } finally {
        putenv($name);
        putenv($originalTmpdir === false ? 'TMPDIR' : 'TMPDIR='.$originalTmpdir);
        rmdir($directory);
    }
});
