<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Operations\GatewayLocalOperationTokenAuthorizer;
use Orbit\Core\Security\OperationTokenCommandContext;
use SensitiveParameter;

final readonly class GatewayLocalCommandDispatcher
{
    public function __construct(
        private GatewayLocalOperationTokenAuthorizer $authorizer,
        private RemoteOrbitGatewayExecutor $executor,
    ) {}

    /**
     * @param  array{
     *     operationId: string,
     *     operationToken: string,
     *     auditLine: string,
     *     argv: list<string>,
     *     commandContext: OperationTokenCommandContext,
     * }  $dispatch
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     environment: array<string, string>,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     force_remote_host?: bool,
     * }  $dispatchOptions
     */
    public function run(
        Node $node,
        string $commandName,
        string $script,
        #[SensitiveParameter]
        array $dispatch,
        array $dispatchOptions,
    ): RemoteShellResult {
        $trustedExecution = $this->authorizer->authorize(
            compactToken: $dispatch['operationToken'],
            expectedNode: $node->name,
            expectedCommand: $commandName,
            commandContext: $dispatch['commandContext'],
        );
        $dispatchOptions['environment'] = [
            ...$dispatchOptions['environment'],
            ...$trustedExecution->environment(),
        ];

        return $this->executor->run(
            node: $node,
            script: $script,
            options: $dispatchOptions,
        );
    }
}
