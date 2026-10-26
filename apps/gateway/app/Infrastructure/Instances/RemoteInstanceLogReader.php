<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\Logs\InstanceLogReader;
use App\Domain\Logs\LogReadLimit;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use Throwable;

/**
 * Reads the newest lines of an Instance's Laravel log through the Node agent's `instances.read-application-log`,
 * which picks the file itself, so a link in the checkout cannot point the read elsewhere.
 */
final readonly class RemoteInstanceLogReader implements InstanceLogReader
{
    public function __construct(private AgentCommandRunner $agents) {}

    public function tail(Instance $instance, int $lines): string
    {
        $path = StoragePath::tryParse($instance->source_is_laravel === true ? $instance->applicationDirectory() : $instance->checkout_path);

        if ($path === null) {
            throw new ResourceOperationException(
                errorCode: 'instance.checkout_path_invalid',
                message: "Instance [{$instance->name}] has an invalid checkout path.",
            );
        }

        try {
            $log = $this->agents->run($instance->node, 'instances.read-application-log', [
                'directory' => $path->value,
                'lines' => $lines,
                'max_bytes' => LogReadLimit::Bytes,
            ]);
        } catch (Throwable) {
            throw new ResourceOperationException(
                errorCode: 'instance.logs_failed',
                message: "The application log of Instance [{$instance->name}] could not be read.",
                status: 502,
            );
        }

        return LogReadLimit::wholeLines(is_string($log['text'] ?? null) ? $log['text'] : '');
    }
}
