<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Models\Node;

final readonly class NativeGatewayCertificatePublisher
{
    public function __construct(
        private AgentCommandRunner $agents,
        private string $orbitHome,
    ) {}

    public function publish(Node $node, GatewayCertificatePaths $certificate): void
    {
        $input = [
            'certificate' => $this->read($certificate->certificatePath),
            'key' => $this->read($certificate->privateKeyPath),
            'root_certificate' => $this->read(rtrim($this->orbitHome, '/').'/ca/root.pem'),
        ];

        try {
            $this->agents->run($node, 'gateway.publish-certificate', $input);
        } catch (AgentCommandRefused $refused) {
            [$step, $code] = match (true) {
                $refused->errorCode === 'gateway.certificate_invalid' => ['validate', 'gateway.certificate_invalid'],
                $refused->errorCode === 'gateway.certificate_recovery_failed',
                ($refused->details['stage'] ?? null) === 'reload' => ['reload', 'gateway.caddy_start_failed'],
                in_array($refused->details['stage'] ?? null, ['stage', 'read', 'validate'], true) => ['stage', 'gateway.certificate_install_failed'],
                default => ['publish', 'gateway.certificate_publish_failed'],
            };
            throw new NodeProvisioningException('gateway-certificate-'.$step, $code, $refused->getMessage(), previous: $refused, result: AgentStep::output($refused));
        } catch (AgentRunnerFailure $failure) {
            throw new NodeProvisioningException('gateway-certificate-publish', 'gateway.certificate_publish_failed', 'Gateway certificate publication did not return a result.', previous: $failure, result: $failure->result);
        }
    }

    private function read(string $path): string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new NodeProvisioningException('gateway-certificate-stage', 'gateway.certificate_install_failed', 'The Gateway certificate input could not be read.');
        }

        return $bytes;
    }
}
