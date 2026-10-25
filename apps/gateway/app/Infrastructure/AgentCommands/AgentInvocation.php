<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Domain\GitHub\GitReadEnvironment;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;

/**
 * One agent command as a program an image build runs on a Linux guest that already holds the pinned agent (ADR 0225).
 * It is the same argv and envelope the runner sends; only the runner's install on mismatch is missing, because the
 * build installs the pin before its first program.
 */
final readonly class AgentInvocation
{
    public function __construct(
        private AgentContract $contract,
        private AgentDelivery $delivery,
    ) {}

    /**
     * The envelope the agent reads on standard input: the pin, the budget, and the input.
     *
     * @param  array<string, mixed>  $input
     */
    public static function envelope(string $pin, float $budgetSeconds, array $input, ?int $streamBytes = null): string
    {
        return json_encode(['agent_sha256' => $pin, 'deadline_ms' => (int) floor($budgetSeconds * 1000), 'input' => (object) $input, ...($streamBytes === null ? [] : ['stream_bytes' => $streamBytes])], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * The input as the agent receives it, with each credential in its wire form, and whether it carries one.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public static function wire(array $input): array
    {
        $credential = false;
        foreach ($input as $key => $value) {
            if ($value instanceof AgentGitHubToken) {
                $credential = true;
                $input[$key] = $value->value;
            }
            if ($value instanceof GitReadEnvironment) {
                $credential = $credential || ! $value->isEmpty();
                $input[$key] = ['github_token' => $value->githubToken()];
            }
        }

        return [$input, $credential];
    }

    /** @param  array<string, mixed>  $input */
    public function command(Node $node, string $command, array $input): RemoteCommand
    {
        $spec = $this->contract->command($command);

        return new RemoteCommand(
            [...($spec->needsSudo('linux') ? ['sudo', '-n'] : []), SshAgentTransport::LinuxBinary, 'command', $spec->name],
            input: self::envelope($this->delivery->pin($node), (float) $spec->timeoutSeconds, $input),
        );
    }
}
