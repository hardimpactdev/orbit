<?php

declare(strict_types=1);

use App\Infrastructure\Tasks\TaskUnitProgram;
use Tests\Support\TaskUnitStateFixture;

it('waits for a queued start before recording its completion', function (string $mainCode, int $expected): void {
    $fixture = new TaskUnitStateFixture;
    unlink($fixture->receipt);
    $fixture->state['ExecMainCode'] = $mainCode;
    touch($fixture->home.'/queued-once');
    expect(posix_mkfifo($fixture->home.'/finish', 0600))->toBeTrue();

    $process = $fixture->operation('run');
    $process->run();

    expect($process->getExitCode())->toBe($expected, $process->getErrorOutput());
    $receipt = json_decode(file_get_contents($fixture->receipt), true, flags: JSON_THROW_ON_ERROR);
    expect($receipt['state'])->toBe('finished')->and($receipt['exit_code'])->toBe($expected);
    expect(file_exists($fixture->home.'/queued-once'))->toBeFalse();
})->with([['0', 128], ['1', 0]]);

it('seals a pre-exec resource failure without an exit status', function (): void {
    $fixture = new TaskUnitStateFixture;

    expect($fixture->invoke('seal')['state'])->toBe('sealed');
});

it('settles a pre-exec resource failure as exit 128 and retires its unit', function (): void {
    $fixture = new TaskUnitStateFixture;

    $result = $fixture->invoke('probe');

    expect($result['state'])->toBe('finished')->and($result['exit_code'])->toBe(128);
    expect(file_get_contents($fixture->home.'/stops'))->toContain('systemctl stop');
});

it('holds probe and seal while a unit has a pending job', function (string $mode): void {
    $fixture = new TaskUnitStateFixture;
    $fixture->state = [...$fixture->state, 'ActiveState' => 'inactive', 'SubState' => 'dead', 'Job' => '45809123'];

    expect($fixture->invoke($mode)['state'])->toBe('running');
    expect(file_exists($fixture->home.'/stops'))->toBeFalse();
})->with(['probe', 'seal']);

it('preserves successful failed and signalled exit codes', function (string $mainCode, string $status, int $expected): void {
    $fixture = new TaskUnitStateFixture;
    $fixture->state = [...$fixture->state, 'ExecMainCode' => $mainCode, 'ExecMainStatus' => $status];

    $result = $fixture->invoke('probe');

    expect($result['state'])->toBe('finished')->and($result['exit_code'])->toBe($expected);
})->with([['1', '0', 0], ['1', '3', 3], ['2', '15', 143]]);

it('keeps a running receipt held when its unit is missing', function (): void {
    $fixture = new TaskUnitStateFixture;
    $fixture->state = [...$fixture->state, 'LoadState' => 'not-found', 'ActiveState' => 'inactive', 'SubState' => 'dead'];

    expect($fixture->invoke('probe')['state'])->toBe('running');
    expect(file_exists($fixture->home.'/stops'))->toBeFalse();
});

it('does not retire or rewrite a check with a pending job', function (): void {
    $fixture = new TaskUnitStateFixture;
    $fixture->state = [...$fixture->state, 'ActiveState' => 'inactive', 'SubState' => 'dead', 'Job' => '45809123', 'Description' => 'Orbit task check '.$fixture->operation];
    mkdir($fixture->home.'/check-starts');
    $receipt = $fixture->home.'/check-starts/'.$fixture->operation.'.json';
    $fixture->write($receipt, ['run_id' => $fixture->operation, 'boot' => $fixture->boot, 'state' => 'started', 'pid' => 999999999, 'started' => '123']);
    $before = file_get_contents($receipt);
    file_put_contents($fixture->home.'/check', TaskUnitProgram::read('tasks/check'));

    $process = $fixture->process(['python3', '-I', $fixture->home.'/check', 'status', '999999999', '123', $fixture->operation]);
    $process->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(['state' => 'running']);
    expect(file_get_contents($receipt))->toBe($before);
    expect(file_exists($receipt.'.lock'))->toBeFalse();
    expect(file_exists($fixture->home.'/stops'))->toBeFalse();
});
