<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\Interrupted;
use App\E2E\IssueState;
use App\E2E\TopologyAcquirer;
use App\E2E\TopologyReleaser;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyRequest;
use Throwable;

final class AcquireCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:acquire {issue} {worktree}'
        .' {--nodes= : Comma-separated workload Nodes from resources/topology-nodes.json, each with optional +ROLE; default app-dev,app-prod}'
        .' {--replace : Release the issue\'s held topology first, if it has one}'
        .' {--json}';

    #[\Override]
    protected $description = 'Compose a fresh topology from role images on the mounted worktree';

    public function handle(TopologyAcquirer $acquirer, TopologyReleaser $releaser): int
    {
        try {
            // A signal unwinds the work like a failure, so the build or acquisition removes what it created.
            $this->trap([SIGINT, SIGTERM, SIGHUP], static function (int $signal): never {
                throw Interrupted::bySignal($signal);
            });
            $request = new TopologyRequest($this->stringArgument('issue'), $this->stringArgument('worktree'));
            $nodes = $this->option('nodes');
            $recipe = TopologyRecipe::fromOption(is_string($nodes) ? $nodes : null);
            $timings = [];
            $started = microtime(true);
            if ($this->option('replace') && IssueState::forWorktree($request->issue, $request->worktree)->hasAttempt()) {
                $released = $releaser->release($request);
                $timings['release'] = round(microtime(true) - $started, 1);
                $this->log($request, 'replaced attempt='.$released['attempt_id']);
            }
            $topology = $acquirer->acquire($request, $recipe, function (string $step, float $seconds) use (&$timings): void {
                $timings[$step] = round($seconds, 1);
                if (! $this->option('json')) {
                    $this->getOutput()->getErrorStyle()->writeln(sprintf('%-20s %7.1f s', $step, $seconds));
                }
            });
            $timings['total'] = round(microtime(true) - $started, 1);
            $this->log($request, 'attempt='.$topology->attempt->value.' nodes='.implode(',', $recipe->nodeKeys()).' seconds='.$timings['total'].' ok');
            $this->outputJson(
                [
                    'state' => 'acquired',
                    'issue' => $request->issue,
                    'attempt_id' => $topology->attempt->value,
                    'worktree' => $request->worktree,
                    'nodes' => $recipe->nodeKeys(),
                    'timings' => $timings,
                    'topology' => $topology->toArray(),
                ],
                'acquired '.$topology->attempt->value.' '.implode(',', $recipe->nodeKeys()).' in '.$timings['total'].' s',
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if (isset($request)) {
                $this->log($request, 'failed: '.$exception->getMessage());
            }
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }
}
