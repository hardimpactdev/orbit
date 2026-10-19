<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmHost;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Models\Node;

/**
 * Runs commands in the VM `$vm` of an Incus host with `incus exec` over the host's SSH connection. The VM needs no
 * address of its own: the host is the trusted path to it. Root commands run as the guest agent's root; user commands
 * go through `runuser`, as enrollment's programs run as the user over SSH.
 */
final readonly class IncusGuestShell implements TaskVmGuestShell
{
    public function __construct(
        private IncusHost $incus,
        private TaskVmHost $host,
        private Node $node,
        public string $vm,
        private ?string $recipeLog = null,
    ) {}

    public function forRecipePhase(string $profile, string $phase): self
    {
        return new self($this->incus, $this->host, $this->node, $this->vm, self::recipeLog($profile, $phase));
    }

    public static function recipeLog(string $profile, string $phase): string
    {
        return '/var/log/orbit/recipe-images/'.$profile.'-'.$phase.'.log';
    }

    public function recordRecipeFailure(string $message): void
    {
        $this->appendLog('Gateway observation: '.new CommandActivityInputSanitizer()->redactText($message)."\n");
    }

    private function record(CommandResult $result): CommandResult
    {
        if ($this->recipeLog !== null) {
            $text = new CommandActivityInputSanitizer()->redactText($result->stdout."\n".$result->stderr);
            $this->appendLog("Exit code [{$result->exitCode}]\n".$text."\n");
        }

        return $result;
    }

    private function appendLog(string $text): void
    {
        if ($this->recipeLog !== null) {
            $this->incus->run($this->host, $this->node, ['exec', '--', $this->vm, 'bash', '-ceu',
                'umask 077; install -d -m 0700 /var/log/orbit/recipe-images; cat >> "$1"; chmod 0600 "$1"', '--', $this->recipeLog],
                $text, 30);
        }
    }

    public function asUser(array $command, string|ProtectedInput|null $input = null, float $timeout = 900.0, ?string $cwd = null): CommandResult
    {
        return $this->record($this->incus->run($this->host, $this->node, [
            'exec', '--cwd', $cwd ?? TaskVmCloudInit::Home, '--env', 'HOME='.TaskVmCloudInit::Home, '--env', 'USER='.TaskVmCloudInit::User,
            '--', $this->vm, 'runuser', '-u', TaskVmCloudInit::User, '--', ...$command,
        ], $input, $timeout));
    }

    public function asRoot(array $command, string|ProtectedInput|null $input = null, float $timeout = 900.0): CommandResult
    {
        return $this->record($this->incus->run($this->host, $this->node, ['exec', '--', $this->vm, ...$command], $input, $timeout));
    }
}
