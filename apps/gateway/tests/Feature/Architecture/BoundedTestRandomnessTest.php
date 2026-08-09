<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('keeps bounded randomness out of repository test suites', function (): void {
    $files = Process::path(repo_path())->run(['git', 'ls-files', '-z', '--', 'apps', 'packages']);

    expect($files->successful())->toBeTrue();

    $boundedIdentifiers = ['random_int', 'rand', 'mt_rand', 'numberBetween'];
    $offenders = collect(explode("\0", $files->output()))
        ->filter(
            fn (string $path): bool => (
                preg_match(
                    '#^(?:apps|packages)/[^/]+/tests/.+\.php$#',
                    $path,
                ) === 1
            ),
        )
        ->flatMap(function (string $path) use ($boundedIdentifiers): array {
            $contents = file_get_contents(repo_path($path));

            if (! is_string($contents)) {
                return [];
            }

            return collect(token_get_all($contents))
                ->filter(
                    fn (mixed $lexeme): bool => (
                        is_array($lexeme)
                        && $lexeme[0] === T_STRING
                        && in_array($lexeme[1], $boundedIdentifiers, strict: true)
                    ),
                )
                ->map(fn (array $lexeme): string => "{$path}:{$lexeme[2]} {$lexeme[1]}")
                ->all();
        })
        ->values()
        ->all();

    expect($offenders)->toBeEmpty();
});
