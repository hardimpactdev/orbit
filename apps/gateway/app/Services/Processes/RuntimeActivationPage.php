<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\OperationRun;
use Illuminate\Http\Response;
use Orbit\Core\Enums\OperationStatus;

final readonly class RuntimeActivationPage
{
    public function response(
        RuntimeHibernationScope $scope,
        OperationRun $run,
        string $originalUri,
    ): Response {
        $uri = $this->safeOriginalUri($originalUri);

        return response()
            ->view(
                'runtime-activation',
                [
                    'name' => $scope->displayName(),
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
                "default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; frame-ancestors 'none'",
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
