<?php

declare(strict_types=1);

namespace App\Domain\Certificates;

use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Operations\OperationTransportInput;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentEffectReceipts;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Models\Node;
use App\Models\Operation;
use SensitiveParameter;

/** Only the public CSR is encoded before the common PEM redactor sees the transport result. */
final readonly class CertificateRequestTransport implements OperationEffectTransport, OperationTransportInput
{
    public function __construct(private AgentCommandRunner $agents, private AgentEffectReceipts $receipts) {}

    public function schema(Operation $operation, array $run): array|bool
    {
        return AgentSchemas::inputSchema('certificate.request-leaf');
    }

    public function wireInput(Operation $operation, array $run, #[SensitiveParameter] array $input): mixed
    {
        return AgentSchemas::wireInput($input);
    }

    public function run(Operation $operation, array $run, #[SensitiveParameter] array $input): array
    {
        return $this->publicResult($this->agents->run(Node::query()->findOrFail($operation->node_id), 'certificate.request-leaf', [...$input, 'effect_key' => $run['effect_key']]));
    }

    public function effectful(Operation $operation, array $run): bool
    {
        return true;
    }

    public function receipt(Operation $operation, array $run, #[SensitiveParameter] array $input): array
    {
        $receipt = $this->receipts->read(Node::query()->findOrFail($operation->node_id), 'certificate.request-leaf', $run['effect_key']);
        if (is_array($receipt['result'] ?? null)) {
            $receipt['result'] = $this->publicResult($receipt['result']);
        }

        return $receipt;
    }

    /** @param array<string, mixed> $receipt
     * @return array<string, mixed>
     */
    public function publicResult(array $receipt): array
    {
        if (($receipt['state'] ?? null) !== 'request') {
            return $receipt;
        }
        $request = $receipt['request'] ?? null;
        if (! is_string($request) || ! str_contains($request, '-----BEGIN CERTIFICATE REQUEST-----')) {
            throw new ResourceOperationException('certificate.request_invalid', 'The Node returned no public signing request.', 409);
        }
        unset($receipt['request']);
        $receipt['request_base64'] = base64_encode($request);

        return $receipt;
    }
}
