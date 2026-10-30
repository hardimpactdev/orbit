<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationTransports;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Models\Node;
use App\Models\Operation;

/** Named, convergent Node primitives. No dispatch, locks, retries or receipts. */
final readonly class DirectRoutePublication
{
    public const array Kinds = ['route.caddy_converge', 'route.private_dns_publish'];

    public static function handles(string $kind): bool
    {
        return in_array($kind, self::Kinds, true);
    }

    public static function writer(string $kind): string
    {
        $writers = config('swamp.route_publication_writers', []);
        $writer = is_array($writers) ? ($writers[$kind] ?? 'polled') : null;
        if (! in_array($writer, ['polled', 'direct'], true)) {
            throw new ResourceOperationException('route.writer_invalid', 'Select polled or direct for the Route publication writer.', 503);
        }

        return $writer;
    }

    public static function owns(Operation $operation): bool
    {
        return self::handles($operation->kind) && ($operation->state['route_publication_writer'] ?? 'polled') === 'direct';
    }

    public function assertOperation(Operation $operation): void
    {
        if (! self::owns($operation) || $operation->node_id === null) {
            throw new ResourceOperationException('route.publication_invalid', 'A Node-bound Route publication is required.', 422);
        }
    }

    /** @return array<string, mixed> */
    public function publish(Operation $operation): array
    {
        $this->assertOperation($operation);
        $node = Node::query()->findOrFail($operation->node_id);
        $transport = app(OperationTransports::class)->get($operation->kind === 'route.caddy_converge' ? 'route-caddy' : 'route-dns');
        if (! $transport instanceof RoutePublicationPrimitive) {
            throw new \LogicException('Route publications require a fixed publication primitive.');
        }
        if ($operation->kind === 'route.caddy_converge') {
            $render = app(NodeCaddyfileRenderer::class)->render($node);
            if (! $render->buildable()) {
                return ['error_code' => 'caddy.render_failed', 'stage' => 'render'];
            }
            $result = $transport->publish($operation, CaddyPublicationPlan::input($render, $node->id));
            if (isset($result['error_code'])) {
                return $result;
            }
            if (! in_array($result['result'] ?? null, ['published', 'unchanged'], true)) {
                throw new ResourceOperationException('caddy.result_invalid', 'Caddy returned no publication result.', 502);
            }

            return [...$result, 'ready' => true];
        }
        if (! PrivateDnsPublicationNodes::query()->whereKey($node->id)->exists()) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Only an active VPN Node publishes private DNS.', 422);
        }
        $renderer = app(DevelopmentDnsConfigRenderer::class);
        $result = $transport->publish($operation, [
            'records' => $renderer->render(),
            'catalog' => json_encode(['requesters' => $renderer->registeredRequesters(), ...$renderer->catalog()->toPublished()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n",
        ]);
        if (($result['outcome'] ?? null) === 'refused' && is_string(data_get($result, 'data.error_code'))) {
            return ['error_code' => data_get($result, 'data.error_code')];
        }
        if (($result['outcome'] ?? null) !== 'published' || data_get($result, 'data.ready') !== true) {
            throw new ResourceOperationException('private_dns.result_invalid', 'Private DNS returned no publication result.', 502);
        }

        return [...$result, 'ready' => true];
    }
}
