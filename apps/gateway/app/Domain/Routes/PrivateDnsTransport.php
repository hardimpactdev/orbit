<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Models\Node;
use App\Models\Operation;
use SensitiveParameter;

/** Retained fixed private DNS publication primitive. */
final readonly class PrivateDnsTransport implements RoutePublicationPrimitive
{
    public function __construct(private RemoteProgramCatalog $catalog, private DevelopmentDnsConfigRenderer $renderer) {}

    public function publish(Operation $operation, #[SensitiveParameter] array $input): array
    {
        $node = Node::query()->findOrFail($operation->node_id);

        if (! $this->current($input)) {
            return ['error_code' => 'private_dns.plan_stale'];
        }
        $result = app(RemoteProgramRunner::class)->run($node, $this->catalog->program('route.publish-dns', user: 'root'), $input);

        return ['outcome' => $result->outcome, 'data' => $result->data];
    }

    /** @param array<string, mixed> $input */
    private function current(#[SensitiveParameter] array $input): bool
    {
        $catalog = json_encode(['requesters' => $this->renderer->registeredRequesters(), ...$this->renderer->catalog()->toPublished()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

        return $input['records'] === $this->renderer->render() && $input['catalog'] === $catalog;
    }
}
