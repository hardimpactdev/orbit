<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Gateway;

use InvalidArgumentException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Gateway\DesiredFleetStateResponse;
use Orbit\Sdk\Responses\Gateway\GatewayStatusResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowGatewayStatusRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/gateway/status';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): GatewayStatusResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return new GatewayStatusResponse(
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            version: is_string($data['version'] ?? null) ? $data['version'] : '',
            phpVersion: is_string($data['php_version'] ?? null) ? $data['php_version'] : '',
            laravelVersion: is_string($data['laravel_version'] ?? null) ? $data['laravel_version'] : '',
            requestId: $requestId,
            desiredFleetState: $this->desiredFleetState($data['desired_fleet_state'] ?? null, $requestId),
            release: is_string($data['release'] ?? null) ? $data['release'] : null,
            releaseSha: is_string($data['release_sha'] ?? null) ? $data['release_sha'] : null,
            autoRelease: $this->autoRelease($data['auto_release'] ?? null),
            doctor: $this->doctor($data['doctor'] ?? null),
            reconcile: $this->reconcile($data['reconcile'] ?? null),
        );
    }

    /** @return array{finished_at: ?string, last_pass_ms: int|float|null, unsettled_count: int}|null */
    private function reconcile(mixed $value): ?array
    {
        if (! is_array($value) || ! array_key_exists('finished_at', $value)
            || ($value['finished_at'] !== null && ! is_string($value['finished_at']))
            || ! array_key_exists('last_pass_ms', $value)
            || ($value['last_pass_ms'] !== null && ! is_int($value['last_pass_ms']) && ! is_float($value['last_pass_ms']))
            || ! is_int($value['unsettled_count'] ?? null)) {
            return null;
        }

        return ['finished_at' => $value['finished_at'], 'last_pass_ms' => $value['last_pass_ms'], 'unsettled_count' => $value['unsettled_count']];
    }

    /** @return array{collected_at: ?string, last_run_ms: int|float|null, hidden_nodes: int, issues: list<array{node_id: int, node_name: string, code: string, count: int, since: ?string, newest_since: ?string, resource: array{type: string, id: ?string}|null}>}|null */
    private function doctor(mixed $value): ?array
    {
        if (! is_array($value) || ! array_key_exists('collected_at', $value)
            || ($value['collected_at'] !== null && ! is_string($value['collected_at']))
            || ! is_int($value['hidden_nodes'] ?? null) || $value['hidden_nodes'] < 0
            || ! array_key_exists('last_run_ms', $value)
            || ($value['last_run_ms'] !== null && (! is_int($value['last_run_ms']) && ! is_float($value['last_run_ms']) || $value['last_run_ms'] < 0))
            || ! is_array($value['issues'] ?? null) || ! array_is_list($value['issues'])) {
            return null;
        }
        $issues = [];
        foreach ($value['issues'] as $issue) {
            if (! is_array($issue) || ! is_int($issue['node_id'] ?? null) || $issue['node_id'] < 1
                || ! is_string($issue['node_name'] ?? null) || ! is_string($issue['code'] ?? null)
                || ! is_int($issue['count'] ?? null) || $issue['count'] < 1
                || ! array_key_exists('since', $issue) || ($issue['since'] !== null && ! is_string($issue['since']))
                || ! array_key_exists('newest_since', $issue) || ($issue['newest_since'] !== null && ! is_string($issue['newest_since']))) {
                return null;
            }
            $resource = $issue['resource'] ?? null;
            if ($resource !== null && (! is_array($resource) || ! is_string($resource['type'] ?? null)
                || ! array_key_exists('id', $resource) || ($resource['id'] !== null && ! is_string($resource['id'])))) {
                return null;
            }
            $issues[] = ['node_id' => $issue['node_id'], 'node_name' => $issue['node_name'], 'code' => $issue['code'],
                'count' => $issue['count'], 'since' => $issue['since'], 'newest_since' => $issue['newest_since'],
                'resource' => $resource === null ? null : ['type' => $resource['type'], 'id' => $resource['id']]];
        }

        return ['collected_at' => $value['collected_at'], 'last_run_ms' => $value['last_run_ms'], 'hidden_nodes' => $value['hidden_nodes'], 'issues' => $issues];
    }

    /** A malformed desired state is left out rather than shown with invented values. */
    private function desiredFleetState(mixed $data, string $requestId): ?DesiredFleetStateResponse
    {
        if (! is_array($data)) {
            return null;
        }

        try {
            return DesiredFleetStateResponse::fromGatewayData($data, $requestId);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @return array{enabled: bool, paused: bool, last_checked_at: string|null, last_result: string|null}|null */
    private function autoRelease(mixed $value): ?array
    {
        if (! is_array($value) || ! is_bool($value['enabled'] ?? null) || ! is_bool($value['paused'] ?? null)) {
            return null;
        }

        return [
            'enabled' => $value['enabled'],
            'paused' => $value['paused'],
            'last_checked_at' => is_string($value['last_checked_at'] ?? null) ? $value['last_checked_at'] : null,
            'last_result' => is_string($value['last_result'] ?? null) ? $value['last_result'] : null,
        ];
    }
}
