<?php

declare(strict_types=1);

use App\Infrastructure\Tasks\TaskUnitProgram;
use Symfony\Component\Process\Process;
use Tests\Support\TaskUnitStateFixture;

beforeEach(function (): void {
    $this->unit = new TaskUnitStateFixture;
    $this->checkout = $this->unit->home.'/checkout';
    new Process(['git', 'init', '--quiet', $this->checkout])->mustRun();
    new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'start'], $this->checkout)->mustRun();
    $this->head = trim(new Process(['git', 'rev-parse', 'HEAD'], $this->checkout)->mustRun()->getOutput());
    $this->tree = trim(new Process(['git', 'rev-parse', 'HEAD^{tree}'], $this->checkout)->mustRun()->getOutput());
    $this->directory = $this->checkout.'/.git/orbit';
    mkdir($this->directory.'/check-starts', 0700, true);
    $this->receipt = $this->directory.'/check-starts/'.$this->unit->operation.'.json';
    $this->result = $this->directory.'/check-starts/'.$this->unit->operation.'.result.json';
    $this->unit->write($this->receipt, ['run_id' => $this->unit->operation, 'boot' => $this->unit->boot,
        'state' => 'started', 'pid' => 999999999, 'started' => '123', 'head' => $this->head, 'tree' => $this->tree]);
    $this->completed = ['head_before' => $this->head, 'tree_before' => $this->tree, 'head_after' => $this->head,
        'tree_after' => $this->tree, 'changed_paths' => [], 'exit_code' => 0, 'finished_at' => 123, 'checkout' => $this->checkout];
    $this->unit->write($this->result, $this->completed);
    $this->unit->state = [...$this->unit->state, 'ActiveState' => 'active', 'SubState' => 'running', 'MainPID' => '0',
        'Description' => 'Orbit task check '.$this->unit->operation];
    $this->unit->write($this->unit->home.'/after-kill.json', [...$this->unit->state, 'SubState' => 'exited']);
    file_put_contents($this->directory.'/check', TaskUnitProgram::read('tasks/check'));
});

function drain_status(string $lookup = 'None', bool $populated = false): Process
{
    return test()->unit->process(['python3', '-I', '-c', <<<'PYTHON'
        import builtins, importlib.machinery, importlib.util, io, sys
        loader = importlib.machinery.SourceFileLoader('check', sys.argv[1])
        check = importlib.util.module_from_spec(importlib.util.spec_from_loader('check', loader))
        loader.exec_module(check)
        check.process_started = lambda pid: None if sys.argv[3] == 'None' else sys.argv[3]
        if sys.argv[4] == 'populated':
            check.open = lambda path, *args, **kwargs: (io.StringIO('populated 1\n')
                if path == '/sys/fs/cgroup/fixture/cgroup.events' else builtins.open(path, *args, **kwargs))
        check.status(999999999, '123', sys.argv[2])
        PYTHON, test()->directory.'/check', test()->unit->operation, $lookup, $populated ? 'populated' : 'native']);
}

it('drains a completed analysis result with its ISO timestamp', function (): void {
    $this->unit->write($this->result, [...$this->completed, 'analysis' => true, 'finished_at' => '2026-10-28T00:00:00+00:00']);

    $process = drain_status()->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['state'])->toBe('finished');
    expect(file_get_contents($this->unit->home.'/stops'))->toContain('systemctl kill --signal=KILL');
});

it('does not kill descendants with an incomplete final-tree result', function (string $field): void {
    unset($this->completed[$field]);
    $this->unit->write($this->result, $this->completed);

    $process = drain_status()->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(['state' => 'running']);
    expect(file_exists($this->unit->home.'/stops'))->toBeFalse();
})->with(['head_after', 'tree_after', 'tree_before']);

it('does not drain an analysis result without a valid zoned completion timestamp', function (mixed $timestamp): void {
    $this->unit->write($this->result, [...$this->completed, 'analysis' => true, 'finished_at' => $timestamp]);

    $process = drain_status()->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(['state' => 'running']);
    expect(file_exists($this->unit->home.'/stops'))->toBeFalse();
})->with(['invalid', '2026-10-28T00:00:00', 123, null]);

it('uses exact-unit supervisor exit proof despite an unrelated process lookup match', function (): void {
    $process = drain_status('123')->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['state'])->toBe('finished');
    expect(file_get_contents($this->unit->home.'/stops'))->toContain('systemctl kill --signal=KILL');
});

it('does not signal a different unit or a queued transition', function (string $property, string $value): void {
    $this->unit->state[$property] = $value;

    $process = drain_status()->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(['state' => 'running']);
    expect(file_exists($this->unit->home.'/stops'))->toBeFalse();
})->with(['different identity' => ['Description', 'Another task check'], 'pending job' => ['Job', '123']]);

it('keeps the lease fenced when the cgroup remains populated after signaling', function (): void {
    $this->unit->write($this->unit->home.'/after-kill.json', [...$this->unit->state, 'SubState' => 'exited', 'ControlGroup' => '/fixture']);

    $process = drain_status(populated: true)->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(['state' => 'running']);
    expect(json_decode(file_get_contents($this->receipt), true, flags: JSON_THROW_ON_ERROR)['state'])->toBe('started');
    expect(file_get_contents($this->unit->home.'/stops'))->toBe('-n systemctl kill --signal=KILL orbit-task-check-'.$this->unit->operation.'.service'."\n");
});

it('reports a failed signal without releasing the run', function (): void {
    unlink($this->unit->home.'/after-kill.json');
    file_put_contents($this->unit->home.'/kill-exit', '1');

    $process = drain_status();
    $process->run();

    expect($process->getExitCode())->not->toBe(0);
    expect($process->getErrorOutput())->toContain('The completed check descendants could not be stopped.');
    expect(json_decode(file_get_contents($this->receipt), true, flags: JSON_THROW_ON_ERROR)['state'])->toBe('started');
});

it('accepts a concurrent drain after a failed signal only with fresh terminal proof', function (): void {
    file_put_contents($this->unit->home.'/kill-exit', '1');

    $process = drain_status()->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['state'])->toBe('finished');
});
