<?php

declare(strict_types=1);

use App\Infrastructure\Processes\CommandResult;
use Symfony\Component\Process\Process;
use Tests\Support\PythonPrograms;

function program_runtime(string $body, string $payload = '{}', ?string $bootstrap = null): CommandResult
{
    return new PythonPrograms()->run(
        $bootstrap ?? file_get_contents(resource_path('remote/bootstrap.py')),
        'tests.program',
        file_get_contents(resource_path('remote/lib/program.py'))."\n".$body."\0".$payload,
    );
}

it('returns one JSON outcome line with escaped data', function (): void {
    $result = program_runtime("run_program(lambda payload: ('done', {'echo': payload['value']}))", '{"value":"a\u00e9"}');

    expect($result->exitCode)->toBe(0)->and($result->stderr)->toBe('')
        ->and($result->stdout)->toBe('{"outcome":"done","data":{"echo":"a\u00e9"}}'."\n");
});

it('returns a refusal outcome and logs the failed check', function (): void {
    $result = program_runtime("def main(payload):\n    require(False, 'path_mismatch', 'the checkout is inside the apps root')\nrun_program(main)");

    expect($result->exitCode)->toBe(0)
        ->and(json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR))->toBe(['outcome' => 'path_mismatch', 'data' => []])
        ->and($result->stderr)->toBe("path_mismatch: the checkout is inside the apps root\n");
});

it('rejects input that is not an object without a result', function (): void {
    $result = program_runtime("run_program(lambda payload: ('done', {}))", '[]');

    expect($result->exitCode)->not->toBe(0)->and($result->stdout)->toBe('');
});

it('names the program when an exception prevents its result', function (): void {
    $result = program_runtime("def main(payload):\n    raise RuntimeError('boom')\nrun_program(main)");

    expect($result->exitCode)->not->toBe(0)->and($result->stdout)->toBe('')
        ->and($result->stderr)->toContain('File "tests.program"');
});

it('does not import modules from the working directory', function (): void {
    $process = new Process(['python3', '-I', '-c', file_get_contents(resource_path('remote/bootstrap.py')), 'tests.program'], resource_path('remote/lib'));
    $process->setInput(file_get_contents(resource_path('remote/lib/program.py'))."\nrun_program(lambda payload: ('done', {'json': json.__file__}))\0{}");
    $process->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['data']['json'])->not->toContain(resource_path('remote'));
});

it('rejects an unsupported interpreter version before producing a result', function (): void {
    $result = program_runtime("run_program(lambda payload: ('done', {}))", bootstrap: "import sys\nsys.version_info = (3, 9, 18, 'final', 0)\n".file_get_contents(resource_path('remote/bootstrap.py')));

    expect($result->exitCode)->toBe(1)->and($result->stdout)->toBe('')
        ->and($result->stderr)->toContain('tests.program: Orbit remote programs need Python 3.10 or newer');
});

it('ends on SIGTERM without producing a result', function (): void {
    $process = new Process(['python3', '-I', '-c', file_get_contents(resource_path('remote/bootstrap.py')), 'tests.program']);
    $process->setInput(file_get_contents(resource_path('remote/lib/program.py'))."\nimport time\ndef main(payload):\n    print('ready', file=sys.stderr, flush=True)\n    time.sleep(30)\nrun_program(main)\0{}");
    $process->setTimeout(5);
    $process->start();
    try {
        expect($process->waitUntil(fn (string $type, string $output): bool => $type === Process::ERR && str_contains($output, "ready\n")))->toBeTrue();
        $process->signal(SIGTERM);
        $process->wait();

        expect($process->getExitCode())->toBe(128 + SIGTERM)->and($process->getOutput())->toBe('');
    } finally {
        $process->stop(0);
    }
});
