<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Models\Node;

/** Requests the static web release directories that Caddy serves on the Gateway Node. */
final readonly class GatewayWebDirectoryConverger
{
    public function __construct(
        private AgentCommandRunner $agents,
        private string $webRoot,
    ) {}

    public function converge(Node $node): void
    {
        try {
            $this->agents->run($node, 'gateway.prepare-web-directory', ['path' => $this->webRoot]);
        } catch (AgentCommandRefused $refused) {
            $invalid = $refused->errorCode === 'gateway.web_directory_invalid';

            throw new NodeProvisioningException(
                step: $invalid ? 'gateway-web-validate' : 'gateway-web-directory',
                errorCode: $invalid ? 'gateway.web_directory_invalid' : 'gateway.web_directory_failed',
                message: $refused->getMessage(),
                previous: $refused,
                result: AgentStep::output($refused),
            );
        } catch (AgentRunnerFailure $failure) {
            throw new NodeProvisioningException(
                step: 'gateway-web-directory',
                errorCode: 'gateway.web_directory_failed',
                message: 'Gateway web directory convergence failed.',
                previous: $failure,
                result: $failure->result,
            );
        }
    }
}
