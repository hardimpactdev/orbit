<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use Closure;

/** Models the guest's immutable staging and retained systemd results at the transport boundary. */
final class RecipeImageUnitGuest
{
    /** @var array<string, string> */
    public array $files = [];

    /** @var array<string, array{state: string, result: string, exit: int}> */
    public array $units = [];

    public int $starts = 0;

    public bool $loseStartReply = false;

    public bool $running = false;

    public bool $timedOut = false;

    /** @param Closure(string, string, string): CommandResult $execute Receives profile, phase and staged source. */
    public function __construct(private Closure $execute) {}

    /** @param list<string> $argv */
    public function run(array $argv, string|ProtectedInput|null $input): ?CommandResult
    {
        $ok = static fn (string $output = ''): CommandResult => new CommandResult(0, $output, '', 1, false);
        if ($argv[0] === 'mktemp') {
            return $ok('/run/orbit-recipe-image.'.str_pad((string) count($this->files), 8, '0', STR_PAD_LEFT));
        }
        if ($argv[0] === 'install' && ($argv[3] ?? null) === '/dev/stdin') {
            $this->files[$argv[4]] = $input instanceof ProtectedInput ? stream_get_contents($input->stream()) : (string) $input;

            return $ok();
        }
        if ($argv[0] === 'mv' && $argv[1] === '--no-clobber') {
            $this->files[$argv[4]] ??= $this->files[$argv[3]];
            unset($this->files[$argv[3]]);

            return $ok();
        }
        if ($argv[0] === 'systemctl' && $argv[1] === 'show') {
            $unit = end($argv);
            if (! isset($this->units[$unit])) {
                return $ok("LoadState=not-found\n");
            }
            $state = $this->units[$unit];

            return $ok("LoadState=loaded\nActiveState=".($state['exit'] === 0 && ! $this->timedOut ? 'active' : 'failed')
                ."\nSubState=".($this->running ? 'running' : 'exited')."\nResult=".($this->timedOut ? 'timeout' : $state['result'])."\nExecMainStatus={$state['exit']}\n");
        }
        if ($argv[0] === 'systemd-run') {
            $this->starts++;
            $unit = substr($argv[2], strlen('--unit='));
            if (! isset($this->units[$unit])) {
                $program = '/run/'.$unit.'.program';
                $source = $this->files[$program];
                $phase = in_array('python3', $argv, true) ? end($argv) : 'pi';
                $profile = $phase === 'pi' ? 'base' : RecipeProgramPayload::read($source)['profile'];
                $result = ($this->execute)($profile, $phase, $source);
                $this->files['/var/log/orbit/recipe-images/'.$profile.'-'.$phase.'.log'] = $result->stdout.$result->stderr;
                $this->units[$unit] = ['state' => 'exited', 'result' => $result->succeeded() ? 'success' : 'exit-code', 'exit' => $result->exitCode];
            }
            if ($this->loseStartReply) {
                $this->loseStartReply = false;

                return new CommandResult(255, '', 'mux_client_read_packet: read header failed: Broken pipe', 1, false);
            }

            return $ok();
        }
        if ($argv[0] === 'tail' && isset($this->files[end($argv)])) {
            return $ok(implode("\n", array_slice(explode("\n", $this->files[end($argv)]), -40)));
        }

        return null;
    }
}
