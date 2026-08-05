<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Narrows a successful proxy-route match into a typed log target.
 */
final readonly class ApplicationLogProxyTarget
{
    /**
     * @param  array{
     *     ok: true,
     *     type: 'instance',
     *     selector: string
     * }|array{
     *     ok: true,
     *     type: 'workspace',
     *     workspace: string,
     *     instance: string
     * }|array{
     *     ok: false,
     *     field: string,
     *     message: string,
     *     meta: array<string, mixed>
     * }  $matched
     * @return array{type: 'instance', selector: string}|array{type: 'workspace', workspace: string, instance: string}|null
     */
    public function fromMatchResult(array $matched): ?array
    {
        if (($matched['ok'] ?? false) !== true) {
            return null;
        }

        if (($matched['type'] ?? null) === 'workspace') {
            $workspace = $matched['workspace'] ?? null;
            $instance = $matched['instance'] ?? null;

            if (! is_string($workspace) || $workspace === '' || ! is_string($instance) || $instance === '') {
                return null;
            }

            return [
                'type' => 'workspace',
                'workspace' => $workspace,
                'instance' => $instance,
            ];
        }

        $selector = $matched['selector'] ?? null;

        if (! is_string($selector) || $selector === '') {
            return null;
        }

        return [
            'type' => 'instance',
            'selector' => $selector,
        ];
    }
}
