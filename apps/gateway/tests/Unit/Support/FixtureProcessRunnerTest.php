<?php

declare(strict_types=1);

use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProtectedInput;
use Tests\Support\FixtureProcessRunner;

it('runs isolated fixture commands with bounded output and protected input', function (): void {
    $input = ProtectedInput::fromString('fixture input');
    $result = (new FixtureProcessRunner)->run(new ProcessInvocation(
        arguments: [PHP_BINARY, '-r', 'fwrite(STDOUT, getenv("FIXTURE_VALUE").stream_get_contents(STDIN)); fwrite(STDERR, "fixture error"); exit(7);'],
        protectedInput: $input,
        maxOutputBytes: 13,
        environment: ['FIXTURE_VALUE' => 'prefix:'],
    ));

    expect($result->exitCode)->toBe(7)
        ->and($result->stdout)->toBe('fixture input')
        ->and($result->stderr)->toBe('fixture error')
        ->and($result->truncated)->toBeTrue();
    expect(fn () => $input->stream())->toThrow(LogicException::class);
});

it('does not substitute fixture execution for production streaming and cancellation proofs', function (array $callbacks): void {
    expect(fn () => (new FixtureProcessRunner)->run(new ProcessInvocation(
        ...['arguments' => ['must-not-run'], ...$callbacks],
    )))->toThrow(LogicException::class, 'Streaming and cancellation proofs require NativeProcessRunner.');
})->with([
    'streaming' => fn (): array => ['output' => static function (): void {}],
    'cancellation' => fn (): array => ['cancelled' => static fn (): bool => false],
]);
