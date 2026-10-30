<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Operations\EffectReceiptMetadata;
use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\RemotePrograms\KeyedRemoteProgramRunner;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Models\Node;
use App\Models\Operation;
use SensitiveParameter;

/** One fixed root program. It receives rendered data, never caller program source. */
final readonly class PrivateDnsTransport implements OperationEffectTransport
{
    public function __construct(private KeyedRemoteProgramRunner $programs, private RemoteProgramCatalog $catalog, private DevelopmentProjectionOperationLock $lock, private DevelopmentDnsConfigRenderer $renderer) {}

    public function schema(Operation $operation, array $run): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['records', 'catalog'],
            'properties' => ['records' => ['type' => 'string'], 'catalog' => ['type' => 'string']]];
    }

    public function run(Operation $operation, array $run, #[SensitiveParameter] array $input): array
    {
        if ($run['name'] !== 'route.publish-dns') {
            throw new ResourceOperationException('operation.execution_forbidden', 'The DNS transport runs one named program.', 422);
        }
        $node = Node::query()->findOrFail($operation->node_id);

        return $this->lock->run(function () use ($node, $input, $run): array {
            if (! $this->current($input)) {
                return ['error_code' => 'private_dns.plan_stale'];
            }
            $result = $this->programs->run($node, $this->catalog->program('route.publish-dns', user: 'root'), $input, $run['effect_key'], effect: EffectReceiptMetadata::fromRun($run));

            return ['outcome' => $result->outcome, 'data' => $result->data];
        });
    }

    /** @param array<string, mixed> $input */
    private function current(#[SensitiveParameter] array $input): bool
    {
        $catalog = json_encode(['requesters' => $this->renderer->registeredRequesters(), ...$this->renderer->catalog()->toPublished()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

        return $input['records'] === $this->renderer->render() && $input['catalog'] === $catalog;
    }

    public function effectful(Operation $operation, array $run): bool
    {
        return true;
    }

    public function receipt(Operation $operation, array $run, #[SensitiveParameter] array $input): array
    {
        $receipt = $this->programs->receipt(Node::query()->findOrFail($operation->node_id), $this->catalog->program('route.publish-dns', user: 'root'), $run['effect_key']);
        if (is_array($receipt['result'] ?? null) && ! isset($receipt['result']['error_code'])) {
            $result = $receipt['result'];
            $receipt['result'] = ['outcome' => $result['outcome'], 'data' => $result['data']];
        }

        return $receipt;
    }
}
