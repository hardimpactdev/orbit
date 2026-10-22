<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Instances\BunDependencyUpdateProgram;
use App\Infrastructure\Instances\NpmDependencyUpdateProgram;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProtectedInput;
use Closure;
use Illuminate\Support\Str;

final readonly class VitePlusUpdateFixture
{
    /** @param list<string> $arguments */
    private function __construct(private string $program, private string $label, private array $arguments, private float $terminateGraceSeconds) {}

    public static function bun(): self
    {
        return new self(BunDependencyUpdateProgram::render(\dependency_update_supervisor_host()), 'bun-update', ['update', '--no-save', '--', '--lockfile-only', '--save-text-lockfile'], BunDependencyUpdateProgram::TerminateGraceSeconds);
    }

    public static function npm(): self
    {
        return new self(NpmDependencyUpdateProgram::render(\dependency_update_supervisor_host()), 'npm-update', ['update', '--no-save'], NpmDependencyUpdateProgram::TerminateGraceSeconds);
    }

    public function directory(string $suffix = ''): string
    {
        $path = sys_get_temp_dir().'/orbit-'.$this->label.'-program-'.Str::uuid().$suffix;
        mkdir($path, 0o700);

        return $path;
    }

    public function vp(string $body): string
    {
        $directory = $this->directory('-bin');
        $checks = ['[ $# -eq '.count($this->arguments).' ]'];
        foreach ($this->arguments as $index => $argument) {
            $checks[] = '[ "$'.($index + 1).'" = '.escapeshellarg($argument).' ]';
        }
        file_put_contents($directory.'/vp', "#!/usr/bin/env bash\nset -eu\n".implode(' && ', $checks)." || exit 9\n".$body);
        chmod($directory.'/vp', 0o700);

        return $directory.'/vp';
    }

    public function run(string $root, string $vp, string $deadline, ?Closure $cancelled = null, float $timeout = 15.0): CommandResult
    {
        return new NativeProcessRunner()->run(new ProcessInvocation(
            arguments: [...\dependency_update_supervisor_host()->launcher(), $this->program, $this->label, $root, $vp, $deadline],
            timeout: $timeout,
            terminateGraceSeconds: $this->terminateGraceSeconds,
            protectedInput: ProtectedInput::holdOpen(),
            cancelled: $cancelled,
        ));
    }
}
