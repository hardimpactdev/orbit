<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\OperationRun;
use Illuminate\Http\Response;
use Orbit\Core\Enums\OperationStatus;

final readonly class RuntimeActivationPage
{
    public const string ACTIVATION_STATE_HEADER = 'X-Orbit-Runtime-Activation-State';

    public const string STATE_PENDING = 'pending';

    public const string STATE_FAILED = 'failed';

    public const int POLL_INTERVAL_SECONDS = 1;

    public function response(
        RuntimeHibernationScope $scope,
        OperationRun $run,
        string $originalUri,
    ): Response {
        $uri = $this->safeOriginalUri($originalUri);
        $failed = $run->status === OperationStatus::Failed;
        $scriptNonce = $failed ? null : bin2hex(random_bytes(16));

        $response = response()
            ->view(
                'runtime-activation',
                [
                    'name' => $scope->displayName(),
                    'failed' => $failed,
                    'refreshUri' => $uri,
                    'retryUri' => $this->retryUri($uri),
                    'scriptNonce' => $scriptNonce,
                    'pollIntervalMs' => self::POLL_INTERVAL_SECONDS * 1000,
                    'activationStateHeader' => self::ACTIVATION_STATE_HEADER,
                    'pendingState' => self::STATE_PENDING,
                ],
                503,
            )
            ->header('Cache-Control', 'no-store, private')
            ->header(
                self::ACTIVATION_STATE_HEADER,
                $failed ? self::STATE_FAILED : self::STATE_PENDING,
            )
            ->header(
                'Content-Security-Policy',
                $this->contentSecurityPolicy($scriptNonce),
            )
            ->header('X-Robots-Tag', 'noindex, nofollow');

        if (! $failed) {
            $response->header('Retry-After', (string) self::POLL_INTERVAL_SECONDS);
        }

        return $response;
    }

    private function contentSecurityPolicy(?string $scriptNonce): string
    {
        $directives = [
            "default-src 'none'",
            "style-src 'unsafe-inline'",
            'img-src data:',
            "base-uri 'none'",
            "frame-ancestors 'none'",
        ];

        if (is_string($scriptNonce) && $scriptNonce !== '') {
            $directives[] = "script-src 'nonce-{$scriptNonce}'";
            $directives[] = "connect-src 'self'";
        }

        return implode('; ', $directives);
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
