<?php

declare(strict_types=1);

use Tests\Support\ForkedPythonPrograms;

it('runs real Python command arguments and stdin independently of its compiled source', function (string $input, int $status): void {
    $host = new ForkedPythonPrograms;
    $source = <<<'PYTHON'
        import base64, json, sys
        print(json.dumps({'arguments': sys.argv, 'input': base64.b64encode(sys.stdin.buffer.read()).decode()}))
        print('command diagnostic', file=sys.stderr)
        sys.exit(int(sys.argv[1]))
        PYTHON;
    try {
        $arguments = [(string) $status, 'space arg', "line\narg", '', '☄'];
        $result = $host->runCommand($source, $arguments, $input);
        $decoded = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        $next = $host->runCommand("print('recovered')", []);

        expect($result->exitCode)->toBe($status)
            ->and($decoded)->toBe(['arguments' => ['-c', ...$arguments], 'input' => base64_encode($input)])
            ->and($result->stderr)->toBe("command diagnostic\n")
            ->and($next->exitCode)->toBe(0)->and($next->stdout)->toBe("recovered\n");
    } finally {
        $host->close();
    }
})->with([
    'empty successful input' => ['', 0],
    'failed command' => ["input ends in newlines\n\n", 7],
    'all bytes and repeated NULs' => [str_repeat(implode('', array_map(chr(...), range(0, 255))), 257)."\0\0\n\n", 0],
]);

it('uses each real command deadline instead of the host default', function (): void {
    $host = new ForkedPythonPrograms;
    try {
        $hung = $host->runCommand("import signal\nprint('started', flush=True)\nsignal.pause()", [], timeoutSeconds: 0.05);
        $result = $host->runCommand("print('started')\nprint('finished')", [], timeoutSeconds: 0.4);

        expect($hung->exitCode)->toBe(124)->and($hung->stdout)->toBe("started\n")
            ->and($hung->stderr)->toContain('Python test program timed out.');

        expect($result->exitCode)->toBe(0)->and($result->stdout)->toBe("started\nfinished\n");
    } finally {
        $host->close();
    }
});
