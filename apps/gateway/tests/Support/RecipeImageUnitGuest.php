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

    /** @var array<string, array{state: string, result: string, exit: int, invocation: string}> */
    public array $units = [];

    public int $starts = 0;

    /** @var array<string, string> */
    private array $phases = [];

    public bool $loseStartReply = false;

    public bool $loseUnsentStart = false;

    public string $bootId = '11111111-1111-4111-8111-111111111111';

    /** @var list<CommandResult> */
    public array $statusReplies = [];

    public bool $loseRestartReply = false;

    public bool $loseUnsentRestart = false;

    public bool $signaled = false;

    public bool $running = false;

    public bool $timedOut = false;

    /** @param Closure(string, string, string): CommandResult $execute Receives profile, phase and staged source. */
    public function __construct(private Closure $execute) {}

    /** @param list<string> $argv */
    public function run(array $argv, string|ProtectedInput|null $input): ?CommandResult
    {
        $ok = static fn (string $output = ''): CommandResult => new CommandResult(0, $output, '', 1, false);
        if ($argv === ['cat', '/proc/sys/kernel/random/boot_id']) {
            return $ok($this->bootId);
        }
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
        if ($argv[0] === 'env' && str_starts_with($argv[1], 'HOME=/var/tmp/orbit-recipe-image-')) {
            $contents = $input instanceof ProtectedInput ? stream_get_contents($input->stream()) : (string) $input;
            if (hash('sha256', $contents) !== end($argv)) {
                return new CommandResult(1, '', 'Input digest mismatch.', 1, false);
            }
            $this->files[substr($argv[1], 5).'/.local/bin/pi-server'] = $contents;

            return $ok();
        }
        if ($argv[0] === 'systemctl' && $argv[1] === 'restart') {
            if ($this->loseUnsentRestart) {
                $this->loseUnsentRestart = false;

                return new CommandResult(255, '', 'Connection closed before restart.', 1, false);
            }
            unset($this->units[end($argv)]);
            $this->start(end($argv));
            if ($this->loseRestartReply) {
                $this->loseRestartReply = false;

                return new CommandResult(255, '', 'Connection closed after restart.', 1, false);
            }

            return $ok();
        }
        if ($argv[0] === 'systemctl' && $argv[1] === 'show') {
            if ($this->statusReplies !== []) {
                return array_shift($this->statusReplies);
            }
            $unit = end($argv);
            if (! isset($this->units[$unit])) {
                return $ok("LoadState=not-found\n");
            }
            $state = $this->units[$unit];

            return $ok("LoadState=loaded\nActiveState=".($state['exit'] === 0 && ! $this->timedOut && ! $this->signaled ? 'active' : 'failed')
                ."\nSubState=".($this->running ? 'running' : 'exited')."\nResult=".($this->timedOut ? 'timeout' : ($this->signaled ? 'signal' : $state['result']))."\nExecMainCode=".($this->signaled ? '2' : '1')."\nExecMainStatus={$state['exit']}\nInvocationID=".$state['invocation']."\n");
        }
        if ($argv[0] === 'systemd-run') {
            if ($this->loseUnsentStart) {
                $this->loseUnsentStart = false;

                return new CommandResult(255, '', 'Connection closed before start.', 1, false);
            }
            $unit = substr($argv[2], strlen('--unit='));
            $this->phases[$unit] = in_array('python3', $argv, true) ? end($argv) : 'pi';
            $this->start($unit);
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

    private function start(string $unit): void
    {
        $this->starts++;
        if (isset($this->units[$unit])) {
            return;
        }
        $source = $this->files['/run/'.$unit.'.program'];
        $name = $this->phases[$unit];
        $profile = $name === 'pi' ? 'base' : RecipeProgramPayload::read($source)['profile'];
        $result = ($this->execute)($profile, $name, $source);
        $this->files['/var/log/orbit/recipe-images/'.$profile.'-'.$name.'.log'] = $result->stdout.$result->stderr;
        $this->units[$unit] = ['state' => 'exited', 'result' => $result->succeeded() ? 'success' : 'exit-code', 'exit' => $result->exitCode, 'invocation' => str_pad((string) $this->starts, 32, '0', STR_PAD_LEFT)];
    }
}
