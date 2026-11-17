<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Nodes\NodeDesiredConvergeInput;
use App\Models\Node;
use App\Support\ValidatedData;
use Illuminate\Console\Command;

/**
 * Prints the digest of each named Node's converge intent as one JSON object, `{"<name>": "<sha256>"}`, or `{"<name>": {"error": "..."}}` for
 * a Node whose intent cannot be built. Without `--base` it is the digest of
 * the default converge intent (NodeDesiredConvergeInput::defaultDigest); with `--base`, the digest of the base intent. It makes no
 * execution copy, admits no Operation and writes nothing. The nested-fleet refresh runs it in the nested Gateway: an unchanged digest is
 * one input of its skipped converge
 * (docs/reference/development-containers.md#what-a-refresh-skips). With `--host=<name>` it also prints, as `host`, the digest of that Node's
 * Instance host intent (InstanceHostIntent::digest), or an empty string when the Node has none yet.
 */
final class NodeConvergeDigestCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:node-converge-digest
        {nodes* : Node names}
        {--base : The base converge instead of the default one}
        {--host= : The Node whose Instance host intent digest it also prints, as host}';

    #[\Override]
    protected $description = 'Print the digest of the default or base converge intent of each named Node, without admitting anything.';

    public function handle(NodeDesiredConvergeInput $input): int
    {
        $digests = [];
        foreach (ValidatedData::stringList($this->argument('nodes')) as $name) {
            $node = Node::query()->where('name', $name)->first();
            if (! $node instanceof Node) {
                $this->error("The Node {$name} is unknown.");

                return self::FAILURE;
            }
            // One Node's intent that cannot be built does not hide the digests of the others: its value names the error, and the refresh
            // reports that Node as `digest_unreadable` with it (docs/reference/development-containers.md#what-a-refresh-skips).
            try {
                $digests[$name] = $this->option('base') ? $input->baseDigest($node) : $input->defaultDigest($node);
            } catch (\Throwable $exception) {
                $digests[$name] = ['error' => mb_strimwidth($exception::class.': '.$exception->getMessage(), 0, 300, '...')];
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
