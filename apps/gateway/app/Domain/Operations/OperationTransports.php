<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\OperationAgentTransport;
use App\Infrastructure\RemotePrograms\OperationRemoteProgramTransport;
use LogicException;

final readonly class OperationTransports
{
    /** @param array<string, class-string<OperationTransport>>|null $definitions */
    public function __construct(private ?array $definitions = null) {}

    public function get(string $name): OperationTransport
    {
        $transports = $this->definitions ?? self::definitions();
        if (! isset($transports[$name])) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The named transport is not registered.', 422);
        }
        $transport = app($transports[$name]);
        if (! $transport instanceof OperationTransport) {
            throw new LogicException('An Operation transport must implement OperationTransport.');
        }

        return $transport;
    }

    /** @return array<string, class-string<OperationTransport>> */
    public static function definitions(): array
    {
        $transports = ['agent' => OperationAgentTransport::class, 'remote_program' => OperationRemoteProgramTransport::class, 'gateway' => OperationGatewayTransport::class];
        foreach (glob(__DIR__.'/../*/operation-transports.php') ?: [] as $file) {
            foreach (require $file as $key => $class) {
                if (isset($transports[$key])) {
                    throw new LogicException("Duplicate Operation transport [{$key}].");
                }
                $transports[$key] = $class;
            }
        }

        return $transports;
    }
}
