<?php

declare(strict_types=1);

use App\Infrastructure\Processes\ProcessInvocation;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\FixtureProcessRunner;
use Tests\Support\ScriptedBashCommand;

it('keeps sourced command arguments status variables and options local to that command', function (string $end, int $status): void {
    $directory = sys_get_temp_dir().'/orbit-scripted-command-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    $script = $directory.'/a scripted command';
    file_put_contents($script, <<<'BASH'
        fixture_state=changed
        set +e +u
        shopt -s nullglob
        printf '<%s>\n' "$@"
        BASH
        ."\n{$end} {$status}\n");
    chmod($script, 0600);

    try {
        $program = ScriptedBashCommand::define('fixture', $script).<<<'BASH'
            fixture_state=original
            shopt -u nullglob
            if fixture 'space arg' 'slash\arg' ''; then status=0; else status=$?; fi
            [[ $fixture_state == original ]]
            [[ $- == *e* && $- == *u* ]]
            ! shopt -q nullglob
            printf 'status=%s\n' "$status"
            BASH;
        $result = (new FixtureProcessRunner)->run(new ProcessInvocation(
            arguments: ['bash', '-eu', '-c', $program],
            timeout: 5,
        ));

        expect($result->exitCode)->toBe(0)
            ->and($result->stdout)->toBe("<space arg>\n<slash\\arg>\n<>\nstatus={$status}\n")
            ->and($result->stderr)->toBe('');
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
})->with([
    'successful return' => ['return', 0],
    'failed return' => ['return', 7],
    'successful exit' => ['exit', 0],
    'failed exit' => ['exit', 7],
]);
