<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\OperationEvent;
use App\Models\OperationRun;
use Illuminate\Http\Response;
use Orbit\Core\Enums\OperationStatus;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class RuntimeActivationPage
{
    public function response(
        RuntimeHibernationScope $scope,
        OperationRun $run,
        string $originalUri,
    ): Response {
        $events = $run->events()->get();
        $tree = $events->first(
            static fn (OperationEvent $event): bool => $event->event_type === 'tree',
        );
        $steps = [];
        /** @mago-expect analyzer:mixed-assignment */
        $rawSteps = $tree instanceof OperationEvent ? $tree->payload['steps'] ?? [] : [];

        if (is_array($rawSteps)) {
            /** @mago-expect analyzer:mixed-assignment */
            foreach ($rawSteps as $rawStep) {
                if (
                    ! is_array($rawStep)
                    || ! is_string($rawStep['key'] ?? null)
                    || ! is_string($rawStep['label'] ?? null)
                ) {
                    continue;
                }

                $steps[] = [
                    'key' => $rawStep['key'],
                    'label' => $rawStep['label'],
                ];
            }
        }

        $statuses = [];

        foreach ($events as $event) {
            if ($event->event_type !== 'step') {
                continue;
            }

            if (
                is_string($event->payload['key'] ?? null)
                && is_string($event->payload['status'] ?? null)
            ) {
                $statuses[$event->payload['key']] = $event->payload['status'];
            }
        }

        $uri = $this->safeOriginalUri($originalUri);
        $steps = array_map(static fn (array $step): array => [
            'key' => $step['key'],
            'label' => $step['label'],
            'status' => $statuses[$step['key']] ?? 'waiting',
        ], $steps);
        $completedSteps = count(array_filter(
            $steps,
            static fn (array $step): bool => $step['status'] === 'done',
        ));
        $totalSteps = count($steps);
        $nonce = base64_encode(random_bytes(18));

        return response()
            ->view(
                'runtime-activation',
                [
                    'name' => $scope->displayName(),
                    'steps' => $steps,
                    'completedSteps' => $completedSteps,
                    'totalSteps' => $totalSteps,
                    'progress' => $totalSteps === 0
                        ? 0
                        : (int) floor(($completedSteps / $totalSteps) * 100),
                    'nonce' => $nonce,
                    'failed' => $run->status === OperationStatus::Failed,
                    'refreshUri' => $uri,
                    'retryUri' => $this->retryUri($uri),
                ],
                503,
            )
            ->header('Cache-Control', 'no-store, private')
            ->header('Retry-After', '2')
            ->header(
                'Content-Security-Policy',
                "default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-{$nonce}'; base-uri 'none'; frame-ancestors 'none'",
            )
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function safeOriginalUri(string $uri): string
    {
        $uri = trim($uri);

        if ($uri === '' || ! str_starts_with($uri, '/') || str_starts_with($uri, '//')) {
            return '/';
        }

        return str_replace(
            search: ["\r", "\n", '"', '<', '>'],
            replace: '',
            subject: $uri,
        );
    }

    private function retryUri(string $uri): string
    {
        return $uri.(str_contains($uri, '?') ? '&' : '?').'orbit-wake-retry=1';
    }
}
