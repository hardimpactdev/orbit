<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Infrastructure\Swamp\SwampCommandStep;

function nativeRunResultFixture(string $status, array $steps): array
{
    return ['id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => $status, 'jobs' => [['name' => 'settings', 'steps' => $steps]]];
}

function nativeRunResultTerminal(array $run, array $selector): array
{
    return new ReflectionMethod(SwampCommandStep::class, 'terminalOutput')->invoke(app(SwampCommandStep::class), $run, $selector);
}

it('retains an exact typed failure code without copying exception text', function (): void {
    $result = app(NativeRunResult::class)->fromRun(nativeRunResultFixture('failed', [
        ['name' => 'Bind identity', 'status' => 'failed', 'error' => 'node.architecture_unsupported'],
        ['name' => 'Private failure', 'status' => 'failed', 'error' => 'command failed: private-secret'],
    ]));
    expect($result['steps']['Bind identity']['error_code'])->toBe('node.architecture_unsupported')
        ->and($result['steps']['Private failure'])->toBe(['status' => 'failed'])
        ->and(json_encode($result))->not->toContain('private-secret');
});

it('keeps every fan-out status and failure after the output capture limit', function (): void {
    $steps = array_map(static fn (int $index): array => ['name' => 'Delete the items-'.$index, 'status' => 'succeeded', 'outputs' => ['stdout' => 'deleted', 'exitCode' => 0]], range(0, 299));
    $steps[] = ['name' => 'Delete the items-300', 'status' => 'failed'];
    $result = app(NativeRunResult::class)->fromRun(nativeRunResultFixture('failed', $steps));

    expect(NativeRunResult::stepStatus($result, 'Delete the items-299'))->toBe('succeeded')
        ->and($result['steps']['Delete the items-299'])->toBe(['status' => 'succeeded'])
        ->and($result['summary']['succeeded'])->toBe(300)
        ->and($result['summary']['failed_steps'])->toBe(['Delete the items-300']);
});

it('keys Swamp run data by step name, with the summary of the workflow-summary report', function (): void {
    $result = app(NativeRunResult::class)->fromRun(nativeRunResultFixture('failed', [
        ['name' => 'Create the apps folder', 'status' => 'succeeded', 'outputs' => ['stdout' => str_repeat('x', 5000), 'exitCode' => 0, 'stderr' => 'private']],
        ['name' => 'Check owner and mode', 'status' => 'failed', 'outputs' => null],
        ['name' => 'Best effort', 'status' => 'failed', 'allowedFailure' => true],
        ['name' => 'Prove the apps folder', 'status' => 'skipped'],
    ]));

    expect($result['steps']['Create the apps folder'])->toBe(['status' => 'succeeded', 'stdout' => str_repeat('x', 4096), 'exit_code' => 0])
        ->and($result['steps']['Check owner and mode'])->toBe(['status' => 'failed'])
        ->and($result['summary'])->toBe(['status' => 'failed', 'succeeded' => 1, 'failed' => 2, 'skipped' => 1, 'failed_steps' => ['Check owner and mode']])
        ->and(json_encode($result))->not->toContain('private');
});

it('reads the native run data for a declaration without a result-reporting step', function (): void {
    $run = nativeRunResultFixture('succeeded', [['name' => 'Check owner and mode', 'status' => 'succeeded', 'outputs' => ['stdout' => 'directory orbit:orbit 755']],
        ['name' => 'Prove the apps folder', 'status' => 'succeeded']]);

    $result = nativeRunResultTerminal($run, ['job' => 'settings', 'step' => 'Check owner and mode', 'native' => true]);

    expect($result['steps']['Check owner and mode']['stdout'])->toBe('directory orbit:orbit 755')
        ->and($result['steps']['Prove the apps folder'])->toBe(['status' => 'succeeded'])
        ->and(nativeRunResultTerminal(nativeRunResultFixture('failed', [['name' => 'Create the apps folder', 'status' => 'failed']]),
            ['job' => 'settings', 'step' => 'Check owner and mode', 'native' => true])['summary']['failed_steps'])->toBe(['Create the apps folder']);
    expect(fn () => nativeRunResultTerminal(nativeRunResultFixture('succeeded', [['name' => 'Other', 'status' => 'succeeded']]),
        ['job' => 'settings', 'step' => 'Check owner and mode', 'native' => true]))->toThrow(ResourceOperationException::class);
});

it('keeps a result-reporting step as it was: its JSON is the result and anything else is unavailable', function (): void {
    $selector = ['job' => 'settings', 'step' => 'result'];

    expect(nativeRunResultTerminal(nativeRunResultFixture('succeeded', [['name' => 'result', 'status' => 'succeeded', 'outputs' => ['stdout' => '{"ready":true}']]]), $selector))->toBe(['ready' => true])
        ->and(nativeRunResultTerminal(nativeRunResultFixture('failed', [['name' => 'result', 'status' => 'skipped']]), $selector))->toBe([]);
    expect(fn () => nativeRunResultTerminal(nativeRunResultFixture('succeeded', [['name' => 'result', 'status' => 'succeeded', 'outputs' => ['stdout' => 'plain']]]), $selector))
        ->toThrow(ResourceOperationException::class);
});

it('accepts native only as true in a family declaration', function (): void {
    $normalize = new ReflectionMethod(DirectOperation::class, 'terminalResult');

    expect($normalize->invoke(null, ['job' => 'settings', 'step' => 'Check owner and mode', 'native' => true]))
        ->toBe(['job' => 'settings', 'step' => 'Check owner and mode', 'native' => true])
        ->and($normalize->invoke(null, ['job' => 'settings', 'step' => 'result']))->toBe(['job' => 'settings', 'step' => 'result']);
    expect(fn () => $normalize->invoke(null, ['job' => 'settings', 'step' => 'result', 'native' => false]))->toThrow(LogicException::class)
        ->and(fn () => $normalize->invoke(null, ['job' => 'settings', 'step' => 'result', 'outputs' => false]))->toThrow(LogicException::class);
});

it('takes the alternative that ran as the end check, and keeps alternatives native only', function (): void {
    $selector = ['job' => 'settings', 'step' => 'Run the read', 'alternatives' => ['Run the read on 209'], 'native' => true];
    $ran = static fn (string $status): array => nativeRunResultFixture('succeeded', [['name' => 'Run the read', 'status' => 'skipped'],
        ['name' => 'Run the read on 209', 'status' => $status, 'outputs' => ['stdout' => 'rows']]]);

    expect(nativeRunResultTerminal($ran('succeeded'), $selector)['steps']['Run the read on 209']['stdout'])->toBe('rows');
    expect(fn () => nativeRunResultTerminal($ran('failed'), $selector))->toThrow(ResourceOperationException::class);
    expect(fn () => nativeRunResultTerminal($ran('succeeded'), ['job' => 'settings', 'step' => 'Run the read', 'native' => true]))->toThrow(ResourceOperationException::class);
    $normalize = new ReflectionMethod(DirectOperation::class, 'terminalResult');
    expect($normalize->invoke(null, $selector))->toBe($selector);
    expect(fn () => $normalize->invoke(null, ['job' => 'settings', 'step' => 'result', 'alternatives' => ['other']]))->toThrow(LogicException::class);
});
