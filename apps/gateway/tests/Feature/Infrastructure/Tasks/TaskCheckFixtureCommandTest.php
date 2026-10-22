<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\StandardLibraryPython;

require_once __DIR__.'/../../../Support/TaskCheckRunnerFixtures.php';

beforeEach(function (): void {
    $this->directory = check_runner_directory();
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

it('keeps its private standard-library interpreter through a real login shell without changing stdin or arguments', function (): void {
    $path = (string) getenv('PATH');
    $input = implode('', array_map(chr(...), range(0, 255)))."\0\n\n";
    $program = <<<'PYTHON'
        import json, os, sys
        sys.stdout.buffer.write(sys.stdin.buffer.read())
        sys.stderr.write(json.dumps([sys.flags.no_site, sys.argv[1:], os.environ['FIXTURE_VALUE']]))
        raise SystemExit(7)
        PYTHON;
    $arguments = ['', "spaces and\nlines", '☄'];
    $process = new Process(['bash', '-lc', 'exec python3 -c "$1" "${@:2}"', '--', $program, ...$arguments], env: [
        'HOME' => $this->directory.'/home',
        'PATH' => StandardLibraryPython::path($path),
        'FIXTURE_VALUE' => "private\nenvironment",
    ], input: $input);
    $process->run();

    expect($process->getExitCode())->toBe(7)
        ->and($process->getOutput())->toBe($input)
        ->and(json_decode($process->getErrorOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([1, $arguments, "private\nenvironment"])
        ->and(getenv('PATH'))->toBe($path);
});

it('executes each working tree check with its own output and status instead of a captured script', function (): void {
    $first = check_runner_checkout('printf first; printf diagnostic >&2; exit 7');
    $second = check_runner_checkout('printf second');
    $command = $this->directory.'/scripted-check-bin/composer';
    $a = new Process([$command, 'check'], $first);
    $b = new Process([$command, 'check'], $second);
    $a->run();
    $b->run();
    file_put_contents($first.'/composer.json', json_encode(['scripts' => ['check' => 'printf updated; exit 3']], JSON_THROW_ON_ERROR));
    $c = new Process([$command, 'check'], $first);
    $c->run();

    expect($a->getExitCode())->toBe(7)->and($a->getOutput())->toBe('first')->and($a->getErrorOutput())->toBe('diagnostic')
        ->and($b->getExitCode())->toBe(0)->and($b->getOutput())->toBe('second')
        ->and($c->getExitCode())->toBe(3)->and($c->getOutput())->toBe('updated');
});

it('refuses every command other than one exact check invocation', function (array $arguments): void {
    $checkout = check_runner_checkout('touch unexpected');
    $process = new Process([$this->directory.'/scripted-check-bin/composer', ...$arguments], $checkout);
    $process->run();

    expect($process->getExitCode())->toBe(64)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toBe("Unexpected scripted check invocation\n")
        ->and(is_file($checkout.'/unexpected'))->toBeFalse();
})->with([
    'no command' => [[]],
    'install' => [['install']],
    'extra argument' => [['check', 'extra']],
]);
