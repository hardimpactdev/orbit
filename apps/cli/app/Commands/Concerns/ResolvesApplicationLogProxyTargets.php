<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Exceptions\GatewayApiException;
use App\Services\ApplicationLogs\ApplicationLogGatewayClient;
use App\Services\ApplicationLogs\ApplicationLogProxyTarget;

/**
 * Gateway proxy/inventory helpers for application-log target resolution.
 *
 * @phpstan-require-extends \App\Commands\GatewayCommand
 */
trait ResolvesApplicationLogProxyTargets
{
    /**
     * @return array<string, mixed>|null
     */
    protected function workspaceDataForCwd(): ?array
    {
        $cwd = $this->hostCwd();

        if ($cwd === null) {
            return null;
        }

        try {
            $response = $this->gatewayGet('/api/workspaces/resolve-by-path', [
                'path' => $cwd,
            ]);
        } catch (GatewayApiException) {
            return null;
        }

        return $this->stringKeyedPayload($this->applicationLogSuccessData($response));
    }

    /**
     * @return array{type: 'instance', selector: string}|array{type: 'workspace', workspace: string, instance: string}|int
     */
    protected function resolveProxyHost(
        string $host,
        ApplicationLogGatewayClient $gatewayClient,
        ApplicationLogProxyTarget $proxyTarget,
    ): array|int {
        try {
            $response = $this->gatewayGet('/api/proxy-routes');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $data = $this->stringKeyedPayload($this->applicationLogSuccessData($response));

        if ($data === null) {
            return $this->renderFailure(
                'validation_failed',
                "No registered proxy route matches host '{$host}'.",
                ['field' => 'target', 'host' => $host],
            );
        }

        $matched = $gatewayClient->matchProxyHost(
            $host,
            $gatewayClient->routeList($data),
        );

        if ($matched['ok'] === false) {
            return $this->renderFailure('validation_failed', $matched['message'], array_merge(
                ['field' => $matched['field']],
                $matched['meta'],
            ));
        }

        $resolved = $proxyTarget->fromMatchResult($matched);

        if ($resolved === null) {
            return $this->renderFailure(
                'validation_failed',
                "Host '{$host}' is not an instance or workspace proxy route.",
                ['field' => 'target', 'host' => $host],
            );
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stringKeyedPayload(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $normalized = [];

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
