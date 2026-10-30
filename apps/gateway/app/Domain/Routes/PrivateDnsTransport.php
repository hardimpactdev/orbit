<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationTransport;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Models\Node;
use App\Models\Operation;
use SensitiveParameter;

/** One fixed root program. It receives rendered data, never caller program source. */
final readonly class PrivateDnsTransport implements OperationTransport, RoutePublicationPrimitive
{
    public function __construct(private RemoteProgramRunner $programs, private RemoteProgramCatalog $catalog, private DevelopmentDnsConfigRenderer $renderer) {}

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

        return $this->publish($operation, $input);
    }

    public function publish(Operation $operation, #[SensitiveParameter] array $input): array
    {
        $node = Node::query()->findOrFail($operation->node_id);

        $catalog = json_encode(['requesters' => $this->renderer->registeredRequesters(), ...$this->renderer->catalog()->toPublished()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        if ($input['records'] !== $this->renderer->render() || $input['catalog'] !== $catalog) {
            return ['error_code' => 'private_dns.plan_stale'];
        }
        $result = $this->programs->run($node, $this->catalog->program('route.publish-dns', user: 'root'), $input);

        return ['outcome' => $result->outcome, 'data' => $result->data];
    }
}
