<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Models\Operation;
use App\Models\WebSocketRuntime;
use App\Support\ValidatedData;

/** DB projection follows proven runtime/Caddy effects. Scoped Settings remain with the Node parent. */
final readonly class WebSocketPublicationStateCommand implements OperationGatewayCommand
{
    public function __construct(private CaddySiteCertificates $certificates, private WebSocketDnsTarget $dns, private WebSocketRegistry $registry) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['action'],
            'properties' => ['action' => ['type' => 'string', 'enum' => ['certificate-withdraw', 'serving', 'retired']]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $nodeId = ValidatedData::integer($operation->node_id);
        match ($input['action']) {
            'certificate-withdraw' => $this->certificates->forget($nodeId, 'websocket'),
            'serving' => $this->dns->markServing($nodeId),
            'retired' => $this->retire($nodeId, ($operation->input['offline'] ?? false) === true),
            default => throw new ResourceOperationException('operation.execution_forbidden', 'The publication action is outside its contract.', 422),
        };

        return ['recorded' => true];
    }

    private function retire(int $nodeId, bool $offline): void
    {
        $this->dns->forget($nodeId);
        $this->registry->write(fn (): int => WebSocketRuntime::query()->where('node_id', $nodeId)->when(! $offline, static fn ($query) => $query->where('slot', 'default'))->update(['required' => false]));
    }
}
