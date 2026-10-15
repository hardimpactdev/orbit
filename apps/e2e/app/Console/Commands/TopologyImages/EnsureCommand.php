<?php

declare(strict_types=1);

namespace App\Console\Commands\TopologyImages;

use App\Console\Commands\E2ECommand;
use App\E2E\Interrupted;
use App\E2E\RecipeRoleImages;
use App\E2E\Value\TopologyRecipe;
use Throwable;

final class EnsureCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology-images:ensure'
        .' {--nodes= : The workload Nodes of the topology, as acquire takes them; default app-dev,app-prod}'
        .' {--recipe-images= : The claimed recipe base image manifest}'
        .' {--json}';

    #[\Override]
    protected $description = 'Import the recipe base used by every topology Node';

    public function handle(RecipeRoleImages $recipes): int
    {
        try {
            // A signal unwinds the work like a failure, so the command reports an interrupted import.
            $this->trap([SIGINT, SIGTERM, SIGHUP], static function (int $signal): never {
                throw Interrupted::bySignal($signal);
            });
            $nodes = $this->option('nodes');
            $needed = TopologyRecipe::fromOption(is_string($nodes) ? $nodes : null)->images();
            $manifest = $this->option('recipe-images');
            if (! is_string($manifest) || $manifest === '') {
                throw new \RuntimeException('A recipe base manifest is required. Pre-recipe groups must drain or be cancelled; local image builds are no longer supported.');
            }
            $imported = $recipes->import($manifest, $needed);
            $this->outputJson(['state' => 'ensured', 'built' => [], 'imported' => $imported], 'imported one recipe base for '.implode(', ', array_keys($imported)));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }
}
