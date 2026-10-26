<?php

declare(strict_types=1);

use App\Infrastructure\Tasks\TaskUnitProgram;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->checkout = sys_get_temp_dir().'/orbit-analysis-base-'.bin2hex(random_bytes(10));
    mkdir($this->checkout, 0700);
    $this->git = function (string ...$arguments): string {
        $process = new Process(['git', '-c', 'user.name=fixture', '-c', 'user.email=fixture@localhost', '-C', $this->checkout, ...$arguments]);
        $process->mustRun();

        return trim($process->getOutput());
    };
    ($this->git)('init', '--quiet');
    file_put_contents($this->checkout.'/tracked', "original\n");
    ($this->git)('add', 'tracked');
    ($this->git)('commit', '--quiet', '-m', 'base');
    ($this->git)('commit', '--quiet', '--allow-empty', '-m', 'starting commit');
    $this->start = ($this->git)('rev-parse', 'HEAD');
    ($this->git)('commit', '--quiet', '--allow-empty', '-m', 'later candidate');
    $this->head = ($this->git)('rev-parse', 'HEAD');
    ($this->git)('remote', 'add', 'origin', 'invalid://must-not-fetch');
    $this->script = $this->checkout.'/.git/check-fixture';
    file_put_contents($this->script, TaskUnitProgram::read('tasks/check'));
    $this->invoke = function (string $start): array {
        $process = new Process(['python3', '-I', $this->script, 'analysis-base', $this->checkout, $start], timeout: 10);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    };
});

afterEach(fn () => new Filesystem()->deleteDirectory($this->checkout));

it('uses the starting commit, not the current head, without changing the candidate', function (): void {
    ($this->git)('update-ref', 'refs/remotes/origin/main', $this->head);
    file_put_contents($this->checkout.'/tracked', "dirty\n");
    file_put_contents($this->checkout.'/untracked', "untracked\n");
    $index = file_get_contents($this->checkout.'/.git/index');
    $refs = ($this->git)('show-ref');

    expect(($this->invoke)($this->start))->toBe(['base' => $this->start])
        ->and(($this->git)('rev-parse', 'HEAD'))->toBe($this->head)
        ->and(($this->git)('show-ref'))->toBe($refs)
        ->and(file_get_contents($this->checkout.'/.git/index'))->toBe($index)
        ->and(file_get_contents($this->checkout.'/tracked'))->toBe("dirty\n")
        ->and(file_get_contents($this->checkout.'/untracked'))->toBe("untracked\n")
        ->and(file_exists($this->checkout.'/.git/FETCH_HEAD'))->toBeFalse()
        ->and(file_exists($this->checkout.'/.git/orbit'))->toBeFalse();
});

it('returns a full object ID as the base of a SHA-256 repository', function (): void {
    $this->checkout .= '/sha256';
    mkdir($this->checkout);
    ($this->git)('init', '--quiet', '--object-format=sha256');
    ($this->git)('commit', '--quiet', '--allow-empty', '-m', 'sha256 start');
    $start = ($this->git)('rev-parse', 'HEAD');
    ($this->git)('update-ref', 'refs/remotes/origin/main', $start);

    expect(strlen($start))->toBe(64)
        ->and(($this->invoke)($start))->toBe(['base' => $start]);
    $this->checkout = dirname($this->checkout);
});

it('neither fetches nor guesses a base without a local main', function (): void {
    expect(($this->invoke)($this->start))->toBe(['base' => ''])
        ->and(file_exists($this->checkout.'/.git/FETCH_HEAD'))->toBeFalse();
});

it('has no base when the local main is unrelated', function (): void {
    $unrelated = ($this->git)('commit-tree', 'HEAD^{tree}', '-m', 'unrelated root');
    ($this->git)('update-ref', 'refs/remotes/origin/main', $unrelated);

    expect(($this->invoke)($this->start))->toBe(['base' => '']);
});

it('does not treat an invalid or missing starting commit as a revision selector', function (string $start): void {
    ($this->git)('update-ref', 'refs/remotes/origin/main', $this->head);

    expect(($this->invoke)($start))->toBe(['base' => '']);
})->with(['empty' => [''], 'HEAD' => ['HEAD'], 'option' => ['--all'], 'unknown' => [str_repeat('a', 40)]]);
