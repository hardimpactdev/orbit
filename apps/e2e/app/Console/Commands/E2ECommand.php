<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\IssueState;
use App\E2E\State\SecretRedactor;
use App\E2E\Value\TopologyRequest;
use App\E2E\WorktreeLocator;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

abstract class E2ECommand extends Command
{
    /** The option every issue command accepts to name the worktree explicitly. */
    protected const string WORKTREE_OPTION = '{--worktree= : The registered Git worktree of the issue}';

    protected function stringArgument(string $name): string
    {
        $value = $this->input->getArgument($name);
        if (! is_string($value)) {
            throw new InvalidArgumentException("The {$name} argument must be a string.");
        }

        return $value;
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->input->getOption($name);
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("The --{$name} option must be a string.");
        }

        return $value;
    }

    protected function outputFailure(Throwable $exception): void
    {
        $message = app(SecretRedactor::class)->redact($exception->getMessage());
        if ($this->option('json')) {
            $this->line(json_encode([
                'state' => 'failed',
                'error' => $message,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($message);
        }
    }

    /** @param array<string, mixed> $payload */
    protected function outputJson(array $payload, string $text): void
    {
        $this->line(
            $this->option('json') ? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : $text,
        );
    }

    /** The issue and its worktree; the attempt is whatever `<worktree>/.e2e/` names. */
    protected function request(): TopologyRequest
    {
        return app(WorktreeLocator::class)->locate(
            $this->stringArgument('issue'),
            $this->input->hasOption('worktree') ? $this->stringOption('worktree') : null,
        );
    }

    protected function state(TopologyRequest $request): IssueState
    {
        return IssueState::forWorktree($request->issue, $request->worktree);
    }

    /** One plain-text line per harness command in `<worktree>/.e2e/log`. */
    protected function log(TopologyRequest $request, string $line): void
    {
        try {
            $this->state($request)->log($this->getName().' '.$line);
        } catch (Throwable) {
            // The log is a convenience; a failed append never fails the command.
        }
    }
}
