<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmHost;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Models\Node;

/** Identifies a trusted Incus guest for agent commands and records recipe failures. */
final readonly class IncusGuestShell
{
    /** @param array{string, string}|null $recipePhase */
    public function __construct(
        private TaskVmHost $host,
        private Node $node,
        public string $vm,
        private ?array $recipePhase = null,
    ) {}

    public function agentTarget(): AgentGuest
    {
        return AgentGuest::incus($this->node, $this->host->project, $this->vm);
    }

    public function forRecipePhase(string $profile, string $phase): self
    {
        return new self($this->host, $this->node, $this->vm, [$profile, $phase]);
    }

    public static function recipeLog(string $profile, string $phase): string
    {
        return '/var/log/orbit/recipe-images/'.$profile.'-'.$phase.'.log';
    }

    public function recordRecipeFailure(string $message): void
    {
        $this->appendLog('Gateway observation: '.new CommandActivityInputSanitizer()->redactText($message)."\n");
    }

    private function appendLog(string $text): void
    {
        if ($this->recipePhase !== null) {
            app(TaskVmImageRecipe::class)->log($this->agentTarget(), $this->recipePhase[0], $this->recipePhase[1], $text);
        }
    }
}
