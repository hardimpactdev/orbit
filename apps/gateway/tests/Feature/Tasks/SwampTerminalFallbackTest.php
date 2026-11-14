<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Infrastructure\Swamp\SwampCommandStep;

/** The create's terminal read: `finish`, or `clone-register` of the same job when `finish` was skipped (a ready clone). */
function createTerminal(array $steps, string $status = 'succeeded'): array
{
    $read = new ReflectionMethod(SwampCommandStep::class, 'terminalOutput');

    return $read->invoke(app(SwampCommandStep::class), ['status' => $status, 'jobs' => [
        ['name' => 'clone', 'steps' => [['name' => 'clone-t3', 'status' => 'succeeded', 'outputs' => ['stdout' => '{"not":"the result"}']]]],
        ['name' => 'create', 'steps' => $steps],
    ]], app(DirectOperation::class)->definition('instance.create')['terminal_result']);
}

it('names clone-register as the create result when finish is skipped', function (): void {
    expect(app(DirectOperation::class)->definition('instance.create')['terminal_result'])
        ->toBe(['job' => 'create', 'step' => 'finish', 'fallback' => 'clone-register']);
});

it('reads finish whenever finish ran, also when clone-register ran or failed beside it', function (): void {
    expect(createTerminal([['name' => 'finish', 'status' => 'succeeded', 'outputs' => ['stdout' => '{"ready":true,"from":"finish"}']]]))
        ->toBe(['ready' => true, 'from' => 'finish'])
        ->and(createTerminal([
            ['name' => 'clone-register', 'status' => 'failed', 'outputs' => []],
            ['name' => 'finish', 'status' => 'succeeded', 'outputs' => ['stdout' => '{"ready":false,"from":"finish"}']],
        ], 'failed'))->toBe(['ready' => false, 'from' => 'finish']);
});

it('reads clone-register\'s stdout when finish was skipped', function (): void {
    expect(createTerminal([
        ['name' => 'clone-register', 'status' => 'succeeded', 'outputs' => ['stdout' => '{"ready":true,"from":"clone-register"}']],
        ['name' => 'finish', 'status' => 'skipped'],
    ]))->toBe(['ready' => true, 'from' => 'clone-register']);
});

it('refuses a fallback on a native selector or on the reporting step itself', function (array $selector): void {
    $check = new ReflectionMethod(DirectOperation::class, 'terminalResult');

    expect(fn () => $check->invoke(null, $selector))->toThrow(LogicException::class);
})->with([
    'native' => [['job' => 'create', 'step' => 'finish', 'fallback' => 'clone-register', 'native' => true]],
    'itself' => [['job' => 'create', 'step' => 'finish', 'fallback' => 'finish']],
    'not a name' => [['job' => 'create', 'step' => 'finish', 'fallback' => ['clone-register']]],
]);
