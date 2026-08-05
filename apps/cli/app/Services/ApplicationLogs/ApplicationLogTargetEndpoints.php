<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Gateway path/stream endpoints for a resolved application-log target.
 */
final readonly class ApplicationLogTargetEndpoints
{
    /**
     * @param  array{type: 'instance', selector: string}|array{type: 'workspace', workspace: string, instance: string}  $resolved
     * @return array{path: string, stream: string, query: array<string, mixed>}
     */
    public function forResolved(array $resolved, ApplicationLogFlags $flags): array
    {
        if (($resolved['type'] ?? null) === 'workspace') {
            $workspace = $resolved['workspace'] ?? null;
            $instance = $resolved['instance'] ?? null;

            if (! is_string($workspace) || ! is_string($instance)) {
                $workspace = is_string($workspace) ? $workspace : '';
                $instance = is_string($instance) ? $instance : '';
            }

            return [
                'path' => '/api/workspaces/'.rawurlencode($workspace).'/log',
                'stream' => '/api/workspaces/'.rawurlencode($workspace).'/log-stream',
                'query' => $flags->query(['instance' => $instance]),
            ];
        }

        $selector = $resolved['selector'] ?? null;

        if (! is_string($selector)) {
            $selector = '';
        }

        return [
            'path' => '/api/instances/'.rawurlencode($selector).'/log',
            'stream' => '/api/instances/'.rawurlencode($selector).'/log-stream',
            'query' => $flags->query(),
        ];
    }
}
