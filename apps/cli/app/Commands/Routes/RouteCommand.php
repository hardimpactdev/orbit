<?php

declare(strict_types=1);

namespace App\Commands\Routes;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Responses\Routes\RouteResponse;

abstract class RouteCommand extends GatewayCommand
{
    use FollowsOperations {
        writeOperationSummary as private writeBaseOperationSummary;
    }

    protected function startRouteOperation(GatewayConfigRepository $repository, GatewayConnector $connector, GatewayRequest $request, string $title): int
    {
        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $key = $this->stringOption('idempotency-key');
        if (is_string($key)) {
            $request->headers()->add('Idempotency-Key', $key);
        }
        $operation = $this->send($connector, $request, OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, $title, 'Route')
            : self::FAILURE;
    }

    private function writeOperationSummary(OperationResponse $operation): void
    {
        $this->writeBaseOperationSummary($operation);
        $retained = $operation->result['retained_on_nodes'] ?? [];
        if ($operation->kind !== 'route.destroy' || ! is_array($retained) || $retained === []) {
            return;
        }
        $this->writeHumanMessage('Left on Nodes the Gateway could not reach:');
        foreach ($retained as $node) {
            if (is_array($node) && is_string($node['node'] ?? null) && is_array($node['steps'] ?? null)) {
                $this->writeHumanMessage($node['node'].': '.implode(', ', $node['steps']));
            }
        }
        $this->writeHumanMessage('Run orbit node:converge NODE once the Node answers to remove them.');
    }

    protected function routeId(): ?int
    {
        return $this->positiveId('route', 'Route', 'route.id_invalid');
    }

    /**
     * Returns the given `--publication` value when it is a non-empty string, or null after rendering
     * `route.publication_invalid`. The Gateway owns the accepted publication values; this only
     * refuses a missing or empty value.
     */
    protected function publication(mixed $publication): ?string
    {
        if (is_string($publication) && $publication !== '') {
            return $publication;
        }

        $this->renderGatewayFailure('route.publication_invalid', 'Publication intent must be a non-empty value.');

        return null;
    }

    protected function renderRoute(RouteResponse $route): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($route->toArray());

            return self::SUCCESS;
        }

        $details = [
            'ID' => $route->id,
            'Kind' => $route->kind,
            'Project' => $route->projectId,
            'Provenance' => $route->provenance,
            'Scope' => $route->clusterId === null ? "Node {$route->nodeId}" : "Cluster {$route->clusterId}",
        ];

        if ($route->kind === 'custom_proxy') {
            $details['Upstream'] = $route->upstream;
            $details['Process'] = $route->processId;
        } else {
            $details['Targets'] = $this->targetList($route);
            $details['Web root'] = $route->webRoot ?? 'Instance root';
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Route: {$route->domain}", [
            ...$details,
            'Publication' => $route->publication,
            'Status' => $route->status,
            'Generation basis Node' => $route->generationBasisNodeId,
            'Replaces Route' => $route->replacesRouteId,
            'Replaced by Route' => $route->replacedByRouteId,
            'Replacement step' => $route->replacementStep,
            'Target set step' => $route->targetSetStep,
            'Failed step' => $route->failedStep,
            'Error' => $route->errorCode,
            'Request ID' => $route->requestId,
        ]));

        return self::SUCCESS;
    }

    protected function targetList(RouteResponse $route): string
    {
        if ($route->kind === 'custom_proxy') {
            $upstream = is_string($route->upstream) && $route->upstream !== '' ? $route->upstream : '—';

            return $route->processId === null ? $upstream : "{$upstream} (process {$route->processId})";
        }

        $targets = $route->targets !== [] ? $route->targets : array_filter([$route->target]);

        return $targets === [] ? '—' : implode(', ', array_map(
            static fn ($target): int => $target->instanceId,
            $targets,
        ));
    }
}
