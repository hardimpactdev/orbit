<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\Power\NodeFileTimes;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use Throwable;

/** The Node agent's `hibernation.times`: one call reads every path. */
final readonly class AgentNodeFileTimes implements NodeFileTimes
{
    public function __construct(private AgentCommandRunner $agents) {}

    public function read(Node $node, array $paths): ?array
    {
        if ($paths === []) {
            return [];
        }

        try {
            $times = $this->agents->run($node, 'hibernation.times', ['paths' => $paths])['times'] ?? null;
        } catch (Throwable) {
            return null;
        }
        if (! is_array($times)) {
            return null;
        }
        $read = [];
        foreach ($paths as $path) {
            $read[$path] = is_int($times[$path] ?? null) ? $times[$path] : null;
        }

        return $read;
    }
}
