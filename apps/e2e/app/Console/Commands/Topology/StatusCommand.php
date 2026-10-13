<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use Throwable;

/** Read-only: reports the issue's discovery topology from its worktree state. */
final class StatusCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:status {issue} '.self::WORKTREE_OPTION.' {--json}';

    #[\Override]
    protected $description = 'Report the issue discovery topology without touching infrastructure';

    public function handle(): int
    {
        try {
            $request = $this->request();
            $state = $this->state($request);
            if (! $state->hasAttempt()) {
                $this->outputJson(['state' => 'absent', 'issue' => $request->issue, 'worktree' => $request->worktree], 'absent');

                return self::SUCCESS;
            }
            $attempt = $state->attempt();
            $topology = $state->topology();
            $this->outputJson(
                [
                    'state' => 'discovery',
                    'issue' => $request->issue,
                    'attempt_id' => $attempt['attempt_id'],
                    'worktree' => $request->worktree,
                    'acquired_at' => $attempt['acquired_at'],
                    'nodes' => $state->recipe()->nodeKeys(),
                    'topology' => $topology?->toArray(),
                ],
                'discovery '.$attempt['attempt_id'].($topology === null ? ' incomplete' : ''),
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }
}
