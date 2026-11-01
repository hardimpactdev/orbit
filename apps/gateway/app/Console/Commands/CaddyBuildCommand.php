<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Routes\NativeCaddyPublicationInput;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\CaddyfileSiteDiff;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddyLiveReader;
use App\Models\Node;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Submits a Node's complete Caddy publication intent to its native workflow, for operators and repair.
 * `--dry-run` renders without pushing, and `--diff` compares that render with the live file site by site.
 */
final class CaddyBuildCommand extends Command
{
    use ReadsStringInput;

    #[\Override]
    protected $signature = 'orbit:caddy-build
        {node : Node name}
        {--dry-run : Render the Caddyfile without pushing it}
        {--diff : With --dry-run, compare the render with the live Caddyfile site by site}';

    #[\Override]
    protected $description = 'Submit a Node Caddy build, or render it with --dry-run.';

    public function handle(NodeCaddyfileRenderer $renderer, NodeCaddyLiveReader $live, StartOperationAction $operations): int
    {
        if ($this->option('diff') && ! $this->option('dry-run')) {
            $this->error('--diff only compares a render. Pass --dry-run --diff.');

            return self::FAILURE;
        }

        $name = $this->stringArgument('node');
        $node = Node::query()->where('name', $name)->first();

        if (! $node instanceof Node) {
            $this->error("Node [{$name}] does not exist.");

            return self::FAILURE;
        }

        if (! $this->option('dry-run')) {
            return $this->build($operations, $node);
        }

        $caddyfile = $renderer->render($node);

        if (! $this->option('diff')) {
            $this->output->write($caddyfile->content, false, OutputInterface::OUTPUT_RAW);
        } else {
            try {
                $this->diff($live->read($node), $caddyfile->content);
            } catch (NodeCaddyBuildException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->line("Build version {$caddyfile->version}, ".count($caddyfile->sites).' sites.');
        }

        foreach ($caddyfile->problems as $problem) {
            $this->error("Build refused: {$problem}");
        }

        return $caddyfile->buildable() ? self::SUCCESS : self::FAILURE;
    }

    private function build(StartOperationAction $operations, Node $node): int
    {
        try {
            app(NativeCaddyPublicationInput::class)->forNode($node);
            $operation = $operations->execute('route.caddy_converge', 'node_route_projection', $node->id, $node->id,
                ['reason' => 'Manual Caddy build'], 'console', null);
        } catch (ResourceOperationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line("Caddy publication submitted for Node [{$node->name}]. Operation [{$operation->id}].");

        return self::SUCCESS;
    }

    private function diff(string $live, string $build): void
    {
        foreach (CaddyfileSiteDiff::compare($live, $build) as $row) {
            $this->line(sprintf('%-10s %s', $row['status'], $row['address']));

            foreach ($row['removed'] as $line) {
                $this->line("             - {$line}");
            }

            foreach ($row['added'] as $line) {
                $this->line("             + {$line}");
            }
        }
    }
}
