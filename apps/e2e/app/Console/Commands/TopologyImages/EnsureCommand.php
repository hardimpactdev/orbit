<?php

declare(strict_types=1);

namespace App\Console\Commands\TopologyImages;

use App\Console\Commands\E2ECommand;
use App\E2E\Interrupted;
use App\E2E\RoleImageBuilder;
use App\E2E\RoleImages;
use App\E2E\Value\RoleImage;
use App\E2E\Value\TopologyRecipe;
use Throwable;

/**
 * Prepares this checkout for a topology of the given Nodes: it builds the role images the topology needs that are
 * missing. A stale image stays, because composition reruns every program on top of it.
 */
final class EnsureCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology-images:ensure'
        .' {--nodes= : The workload Nodes of the topology, as acquire takes them; default app-dev,app-prod}'
        .' {--json}';

    #[\Override]
    protected $description = 'Build the missing role images a topology of these Nodes needs';

    public function handle(RoleImages $images, RoleImageBuilder $builder): int
    {
        try {
            // A signal unwinds the work like a failure, so the build removes what it created.
            $this->trap([SIGINT, SIGTERM, SIGHUP], static function (int $signal): never {
                throw Interrupted::bySignal($signal);
            });
            $nodes = $this->option('nodes');
            $needed = TopologyRecipe::fromOption(is_string($nodes) ? $nodes : null)->images();
            $missing = [];
            foreach ($images->status() as $row) {
                $image = RoleImage::from($row['role']);
                if ($row['state'] === 'missing' && in_array($image, $needed, true)) {
                    $missing[] = $image;
                }
            }
            $built = [];
            $timings = [];
            if ($missing !== []) {
                $built = $builder->build($missing, function (string $stage, float $seconds) use (&$timings): void {
                    $timings[$stage] = round($seconds, 1);
                    if (! $this->option('json')) {
                        $this->getOutput()->getErrorStyle()->writeln(sprintf('%-24s %7.1f s', $stage, $seconds));
                    }
                });
            }
            $this->outputJson(
                ['state' => 'ensured', 'needed' => array_map(static fn (RoleImage $image): string => $image->value, $needed), 'built' => $built, 'timings' => $timings],
                $built === [] ? 'every needed role image exists' : 'built '.implode(', ', array_keys($built)),
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }
}
