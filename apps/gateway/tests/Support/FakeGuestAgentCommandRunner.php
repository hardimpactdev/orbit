<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\AgentCommands\GuestAgentCommandRunner;
use Closure;
use LogicException;

/** Fakes guest command results while keeping image build intent observable. */
final class FakeGuestAgentCommandRunner implements GuestAgentCommandRunner
{
    /** @var list<array{guest: AgentGuest, command: string, input: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<float|null> */
    public array $deadlines = [];

    /** @var list<AgentGuest> */
    public array $installs = [];

    /** @var (Closure(AgentGuest, string, array<string,mixed>): array<string,mixed>)|null */
    public ?Closure $answer = null;

    /** @var (Closure(AgentGuest): bool)|null */
    public ?Closure $installAnswer = null;

    public function runGuest(AgentGuest $guest, string $command, array $input = [], ?float $deadlineSeconds = null): array
    {
        if (app(AgentContract::class)->command($command)->privilege('linux') === null) {
            throw new LogicException("The agent command [{$command}] does not run in a Linux guest.");
        }
        $this->calls[] = ['guest' => $guest, 'command' => $command, 'input' => $input];
        $this->deadlines[] = $deadlineSeconds;

        return $this->answer === null ? ['ready' => true] : ($this->answer)($guest, $command, $input);
    }

    public function installGuest(AgentGuest $guest): bool
    {
        $this->installs[] = $guest;

        return $this->installAnswer === null ? true : ($this->installAnswer)($guest);
    }
}
