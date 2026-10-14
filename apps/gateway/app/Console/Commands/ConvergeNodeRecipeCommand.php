<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Fleet\ConvergeNodeRecipeAction;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\Node;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class ConvergeNodeRecipeCommand extends Command
{
    #[\Override]
    protected $signature = 'node:converge-recipe {node? : Node name or ID} {--recipe= : Require this full recipe identity} {--status : List the recorded recipe of every Node without SSH} {--dry-run : Read versions and show the plan without changing the Node}';

    #[\Override]
    protected $description = 'Converge one Node to the repository recipe and stop on any failed check';

    public function handle(RecipeLoader $loader, ConvergeNodeRecipeAction $converge): int
    {
        try {
            if ($this->option('status')) {
                $rows = Node::query()->orderBy('name')->get()->map(fn (Node $node): array => [
                    'node_id' => $node->id, 'name' => $node->name, 'machine_recipe' => $node->settings['machine_recipe'] ?? null,
                ])->all();
                $this->output->writeln(json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

                return self::SUCCESS;
            }
            if ($this->argument('node') === null) {
                throw new InvalidArgumentException('Name a Node, or use --status to list recorded fleet recipes.');
            }
            $recipe = $loader->load();
            $expected = $this->option('recipe');
            if ($expected !== null && $expected !== $recipe->identity) {
                throw new InvalidArgumentException('The requested recipe identity does not match the checked-out recipe. Nothing was changed.');
            }
            $target = (string) $this->argument('node');
            $node = ctype_digit($target) ? Node::query()->findOrFail((int) $target) : Node::query()->where('name', $target)->firstOrFail();
            $result = $converge->execute($node, $recipe, (bool) $this->option('dry-run'));
            $this->output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->output->writeln(json_encode(['status' => 'failed', 'message' => $exception->getMessage(), 'automatic_rollback' => false], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        }
    }
}
