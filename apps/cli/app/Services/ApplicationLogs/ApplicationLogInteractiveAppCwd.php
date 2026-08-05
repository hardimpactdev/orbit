<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

final readonly class ApplicationLogInteractiveAppCwd
{
    /**
     * @param  array<string, mixed>  $inferred
     * @return array{ok: true, target: array{type: 'instance', selector: string}|array{type: 'workspace', workspace: string, instance: string}, requested: string}|array{ok: false, message: string, reason: string}
     */
    public function normalize(array $inferred): array
    {
        if (isset($inferred['error'])) {
            return [
                'ok' => false,
                'message' => (string) $inferred['error'],
                'reason' => is_string($inferred['reason'] ?? null) ? $inferred['reason'] : 'cwd_target_missing',
            ];
        }

        if (($inferred['type'] ?? null) === 'workspace') {
            return $this->workspace($inferred);
        }

        return $this->instance($inferred);
    }

    /**
     * @param  array<string, mixed>  $inferred
     * @return array{ok: true, target: array{type: 'workspace', workspace: string, instance: string}, requested: string}|array{ok: false, message: string, reason: string}
     */
    private function workspace(array $inferred): array
    {
        $workspace = $inferred['workspace'] ?? null;
        $instance = $inferred['instance'] ?? null;

        if (! is_string($workspace) || $workspace === '' || ! is_string($instance) || $instance === '') {
            return $this->missing();
        }

        return [
            'ok' => true,
            'target' => [
                'type' => 'workspace',
                'workspace' => $workspace,
                'instance' => $instance,
            ],
            'requested' => $workspace,
        ];
    }

    /**
     * @param  array<string, mixed>  $inferred
     * @return array{ok: true, target: array{type: 'instance', selector: string}, requested: string}|array{ok: false, message: string, reason: string}
     */
    private function instance(array $inferred): array
    {
        $selector = $inferred['selector'] ?? null;

        if (! is_string($selector) || $selector === '') {
            return $this->missing();
        }

        return [
            'ok' => true,
            'target' => [
                'type' => 'instance',
                'selector' => $selector,
            ],
            'requested' => $selector,
        ];
    }

    /**
     * @return array{ok: false, message: string, reason: string}
     */
    private function missing(): array
    {
        return [
            'ok' => false,
            'message' => 'No unambiguous application-log target could be inferred from the current directory.',
            'reason' => 'cwd_target_missing',
        ];
    }
}
