<?php

declare(strict_types=1);

use App\Infrastructure\Processes\ProcessInvocation;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\BashFixtureFileReader;
use Tests\Support\FixtureProcessRunner;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/orbit-file-reader-'.bin2hex(random_bytes(12));
    mkdir($this->directory.'/bin', 0700, true);
    $this->file = $this->directory.'/confirmation file';
    file_put_contents($this->directory.'/bin/cat', "#!/bin/bash\nprintf '%s\\n' \"\$@\"\nexit 7\n");
    chmod($this->directory.'/bin/cat', 0700);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->directory);
});

it('copies every confirmation-file byte without invoking a cat process', function (string $contents): void {
    file_put_contents($this->file, $contents);
    $result = (new FixtureProcessRunner)->run(new ProcessInvocation(
        arguments: ['bash', '-eu', '-c', BashFixtureFileReader::forFile($this->file).'cat -- "$1"', '--', $this->file],
        timeout: 5,
        environment: ['PATH' => $this->directory.'/bin:'.getenv('PATH')],
    ));

    expect($result->exitCode)->toBe(0)->and($result->stdout)->toBe($contents)->and($result->stderr)->toBe('');
})->with([
    'empty' => [''],
    'unterminated' => ["  hash\\backslash\t"],
    'trailing newlines' => ["\n hash \n\n\n"],
    'binary and repeated NULs' => [str_repeat(implode('', array_map(chr(...), range(0, 255))), 257)."\0\0\n\n"],
]);

it('preserves missing-file failure without launching the external command', function (): void {
    $result = (new FixtureProcessRunner)->run(new ProcessInvocation(
        arguments: ['bash', '-eu', '-c', BashFixtureFileReader::forFile($this->file).'cat -- "$1"', '--', $this->file],
        timeout: 5,
        environment: ['PATH' => $this->directory.'/bin:'.getenv('PATH')],
    ));

    expect($result->exitCode)->toBe(1)->and($result->stdout)->toBe('')->and($result->stderr)->toContain('No such file or directory');
});

it('delegates unrelated forms and stdin to the external command with unchanged arguments', function (array $arguments): void {
    $result = (new FixtureProcessRunner)->run(new ProcessInvocation(
        arguments: ['bash', '-eu', '-c', BashFixtureFileReader::forFile($this->file).'cat "$@"', '--', ...$arguments],
        timeout: 5,
        environment: ['PATH' => $this->directory.'/bin:'.getenv('PATH')],
        input: 'stdin remains available',
    ));

    expect($result->exitCode)->toBe(7)->and($result->stdout)->toBe(implode("\n", $arguments)."\n")->and($result->stderr)->toBe('');
})->with([
    'stdin' => [[]],
    'another path' => [['--', '/private/another file']],
    'other options' => [['-n', '/private/another file']],
]);
