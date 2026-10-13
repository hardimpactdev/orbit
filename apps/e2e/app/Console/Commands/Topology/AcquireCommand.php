<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\Interrupted;
use App\E2E\TopologyAcquirer;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyRequest;
use Throwable;

final class AcquireCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:acquire {issue} {worktree}'
        .' {--nodes= : Comma-separated workload Nodes: app-dev, app-prod, app-prod-2; default app-dev,app-prod}'
        .' {--json}';

    #[\Override]
    protected $description = 'Compose a fresh discovery topology from role images on the mounted worktree';

    public function handle(TopologyAcquirer $acquirer): int
    {
        try {
            // A signal unwinds the work like a failure, so the build or acquisition removes what it created.
            $this->trap([SIGINT, SIGTERM, SIGHUP], static function (int $signal): never {
                throw Interrupted::bySignal($signal);
            });
            $request = new TopologyRequest((string) $this->argument('issue'), (string) $this->argument('worktree'));
            $nodes = $this->option('nodes');
            $recipe = TopologyRecipe::fromOption(is_string($nodes) ? $nodes : null);
            $timings = [];
            $started = microtime(true);
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
                    'state' => $topology->purpose->value,
                    'issue' => $request->issue,
                    'attempt_id' => $topology->attempt->value,
                    'worktree' => $request->worktree,
                    'nodes' => $recipe->nodeKeys(),
                    'timings' => $timings,
                    'topology' => $topology->toArray(),
                ],
                $topology->purpose->value.' '.$topology->attempt->value.' '.implode(',', $recipe->nodeKeys()).' in '.$timings['total'].' s',
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
