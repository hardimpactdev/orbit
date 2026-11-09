<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Console;

use App\Models\Node;
use Illuminate\Console\Command;
use Orbit\Extensions\AiRouter\Actions\AdmitAiRouterModelForwarderAction;

final class AiRouterModelForwarderCommand extends Command
{
    #[\Override]
    protected $signature = 'ai-router:model-forwarder {node : Node name or ID}
        {--bridge= : The Incus bridge of the Node\'s containers}
        {--address= : The bridge\'s IPv4 address, which containers call}
        {--remove : Take the forwarder off the Node and its address out of the guard}';

    #[\Override]
    protected $description = 'Apply, or with --remove withdraw, the container model forwarder on an agent Node, and admit the Node at the model router guard.';

    public function handle(AdmitAiRouterModelForwarderAction $admit): int
    {
        $name = (string) $this->argument('node');
        $node = Node::query()->where('name', $name)->orWhere('id', ctype_digit($name) ? (int) $name : 0)->first();
        if ($node === null) {
            $this->error("Node [{$name}] not found.");

            return self::FAILURE;
        }
        $bridge = $this->option('bridge');
        $address = $this->option('address');
        $operation = $admit->execute($node, is_string($bridge) ? $bridge : null, is_string($address) ? $address : null,
            (bool) $this->option('remove'), 'ai-router:model-forwarder');
        $this->line("Operation {$operation->id} running");

        return self::SUCCESS;
    }
}
