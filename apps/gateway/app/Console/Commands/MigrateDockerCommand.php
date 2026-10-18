<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Fleet\MigrateDockerAction;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\Node;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Internal Gateway maintenance command; JSON only, with explicit noninteractive consent. */
final class MigrateDockerCommand extends Command
{
    use ReadsStringInput;

    #[\Override]
    protected $signature = 'node:migrate-docker {node : One Node name or ID} {--dry-run : Inventory and show the plan without writes} {--yes : Confirm the maintenance action} {--recipe= : Require this full checked-out recipe identity} {--fallback : Authorize a fresh engine and inventory recreation if downgraded metadata is unreadable} {--restore-policies : Recover journaled policies and running states after manual CE restore}';

    #[\Override]
    protected $description = 'Explicitly migrate one Docker CE Node to Ubuntu docker.io in a planned window';

    public function handle(RecipeLoader $loader, MigrateDockerAction $migration): int
    {
        try {
            if ($this->option('restore-policies') && ($this->option('dry-run') || $this->option('fallback'))) {
                throw new InvalidArgumentException('--restore-policies cannot be combined with --dry-run or --fallback. Nothing was changed.');
            }
            if (! $this->option('dry-run') && ! $this->option('yes')) {
                throw new InvalidArgumentException('Review --dry-run first. --yes is required to confirm Docker migration or policy recovery. Nothing was changed.');
            }
            $recipe = $loader->load();
            if ($this->option('recipe') !== null && $this->option('recipe') !== $recipe->identity) {
                throw new InvalidArgumentException('The requested recipe differs from this checkout. Nothing was changed.');
            }
            $target = $this->stringArgument('node');
            $node = ctype_digit($target) ? Node::query()->findOrFail((int) $target) : Node::query()->where('name', $target)->firstOrFail();
            $report = $migration->execute($node, $recipe, (bool) $this->option('dry-run'), (bool) $this->option('fallback'), (bool) $this->option('restore-policies'));
            $this->output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return ($report['healthy'] ?? true) === false ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            $this->output->writeln(json_encode(['status' => 'failed', 'message' => $exception->getMessage(), 'automatic_rollback' => false], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        }
    }
}
