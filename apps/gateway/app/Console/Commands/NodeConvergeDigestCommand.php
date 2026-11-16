<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Nodes\NodeDesiredConvergeInput;
use App\Models\Node;
use App\Support\ValidatedData;
use Illuminate\Console\Command;

/**
 * Prints the digest of each named Node's base converge intent as one JSON object, `{"<name>": "<sha256>"}`. It builds the intent
 * as `node.converge` with `base: true` does, but makes no execution copy, admits no Operation and writes nothing. The nested-fleet
 * refresh runs it in the nested Gateway: an unchanged digest is one input of its skipped converge
 * (docs/reference/development-containers.md#what-a-refresh-skips). With `--host=<name>` it also prints, as `host`, the digest of that Node's
 * Instance host intent (InstanceHostIntent::digest), or an empty string when the Node has none yet.
 */
final class NodeConvergeDigestCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:node-converge-digest
        {nodes* : Node names}
        {--base : The base converge, the only scope it prints}
        {--host= : The Node whose Instance host intent digest it also prints, as host}';

    #[\Override]
    protected $description = 'Print the digest of the base converge intent of each named Node, without admitting anything.';

    public function handle(NodeDesiredConvergeInput $input): int
    {
        if (! $this->option('base')) {
            $this->error('Only the base converge has a digest: pass --base.');

            return self::FAILURE;
        }
        $digests = [];
        foreach (ValidatedData::stringList($this->argument('nodes')) as $name) {
            $node = Node::query()->where('name', $name)->first();
            if (! $node instanceof Node) {
                $this->error("The Node {$name} is unknown.");

                return self::FAILURE;
            }
            try {
                $digests[$name] = $input->baseDigest($node);
            } catch (\Throwable $exception) {
                $this->error("The base intent of {$name} cannot be built: ".$exception::class);

                return self::FAILURE;
            }
        }
        $host = $this->option('host');
        if (is_string($host)) {
            $node = Node::query()->where('name', $host)->first();
            if (! $node instanceof Node) {
                $this->error("The Node {$host} is unknown.");

                return self::FAILURE;
            }
            $digests['host'] = app(InstanceHostIntent::class)->digest($node) ?? '';
        }
        $this->line(json_encode($digests, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
