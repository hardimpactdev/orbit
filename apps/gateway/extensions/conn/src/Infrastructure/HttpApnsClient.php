<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Infrastructure;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Orbit\Extensions\Conn\Domain\Push\ApnsClient;
use Orbit\Extensions\Conn\Domain\Push\ApnsJwtProvider;
use Orbit\Extensions\Conn\Domain\Push\ApnsMessage;
use Orbit\Extensions\Conn\Domain\Push\ApnsSendResult;
use Orbit\Extensions\Conn\Domain\Push\ConnPush;

/**
 * HTTP/2 Apple Push Notification service client using token-based ES256 authentication.
 * Chooses sandbox or production host per device, retries transient errors with backoff,
 * and renews the JWT once on 403 ExpiredProviderToken. Never logs tokens, JWTs, or titles.
 */
final readonly class HttpApnsClient implements ApnsClient
{
    /** @var list<int> */
    private const array DefaultBackoffMs = [200, 500];

    /**
     * @param  list<int>  $backoffMs
     * @param  (Closure(int): void)|null  $sleeper
     */
    public function __construct(
        private ApnsJwtProvider $jwt,
        private array $backoffMs = self::DefaultBackoffMs,
        private ?Closure $sleeper = null,
        private ?string $hostOverride = null,
    ) {}

    public function send(ApnsMessage $message): ApnsSendResult
    {
        $jwt = $this->jwt->token();
        if ($jwt === null) {
            Log::warning('conn.push_credentials_missing', [
                'event_id' => $message->eventId,
                'device_row_id' => $message->deviceRowId,
            ]);

            return new ApnsSendResult(status: 0, apnsId: null, reason: 'CredentialsNotConfigured');
        }

        $host = $this->hostOverride ?? self::hostFor($message->apnsEnvironment);
        $url = rtrim($host, '/').'/3/device/'.$message->apnsToken;
        $payload = self::payload($message);
        $refreshedToken = false;
        $result = new ApnsSendResult(status: 0, apnsId: null, reason: 'ConnectionFailed');

        for ($attempt = 1; $attempt <= ConnPush::MaxSendAttempts; $attempt++) {
            $result = $this->sendOnce($url, $this->headers($message, $jwt), $payload, $message, $attempt);

            if ($result->status === 403 && $result->reason === 'ExpiredProviderToken' && ! $refreshedToken) {
                $refreshedToken = true;
                $freshJwt = $this->jwt->refresh();
                if ($freshJwt === null) {
                    return new ApnsSendResult(status: 0, apnsId: null, reason: 'CredentialsNotConfigured');
                }
                $jwt = $freshJwt;
                $result = $this->sendOnce($url, $this->headers($message, $jwt), $payload, $message, $attempt);
            }

            if (! $result->isRetryable() || $attempt >= ConnPush::MaxSendAttempts) {
                return $result;
            }

            $delayMs = $this->backoffMs[$attempt - 1] ?? 500;
            if ($delayMs > 0) {
                if ($this->sleeper instanceof Closure) {
                    ($this->sleeper)($delayMs);
                } else {
                    usleep($delayMs * 1000);
                }
            }
        }

        return $result;
    }

    public static function hostFor(string $apnsEnvironment): string
    {
        return $apnsEnvironment === 'production'
            ? ConnPush::ProductionHost
            : ConnPush::SandboxHost;
    }

    /** @return array<string, mixed> */
    public static function payload(ApnsMessage $message): array
    {
        if ($message->pushType === 'background') {
            return [
                'aps' => ['content-available' => 1],
                'environment_id' => $message->environmentId,
                'thread_id' => $message->threadId,
                'kind' => $message->kind,
            ];
        }

        if ($message->isSummary) {
            $title = 'Conn';
            $body = 'Multiple threads need attention';
        } else {
            $title = $message->threadTitle !== null && $message->threadTitle !== ''
                ? $message->threadTitle
                : 'Conn';
            $body = match ($message->kind) {
                'approval' => 'Approval needed',
                'input' => 'Input needed',
                'turn_finished' => 'Turn finished',
                'turn_failed' => 'Turn failed',
                default => 'Thread needs attention',
            };
        }

        return [
            'aps' => [
                'alert' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'sound' => 'default',
            ],
            'environment_id' => $message->environmentId,
            'thread_id' => $message->threadId,
            'kind' => $message->kind,
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     */
    private function sendOnce(string $url, array $headers, array $payload, ApnsMessage $message, int $attempt): ApnsSendResult
    {
        try {
            $response = Http::withOptions(['version' => 2.0])
                ->timeout(10)
                ->connectTimeout(5)
                ->withHeaders($headers)
                ->post($url, $payload);
        } catch (ConnectionException) {
            // Never log the exception message: it contains the APNs URL with the device token.
            Log::warning('conn.push_connection_failed', [
                'event_id' => $message->eventId,
                'device_row_id' => $message->deviceRowId,
                'apns_environment' => $message->apnsEnvironment,
                'attempt' => $attempt,
            ]);

            return new ApnsSendResult(status: 0, apnsId: null, reason: 'ConnectionFailed');
        }

        $apnsId = $response->header('apns-id');
        $decoded = $response->json();
        $reason = is_array($decoded) && is_string($decoded['reason'] ?? null) ? $decoded['reason'] : null;

        return new ApnsSendResult(
            status: $response->status(),
            apnsId: $apnsId !== '' ? $apnsId : null,
            reason: $reason,
        );
    }

    /** @return array<string, string> */
    private function headers(ApnsMessage $message, string $jwt): array
    {
        return [
            'authorization' => 'bearer '.$jwt,
            'apns-push-type' => $message->pushType,
            'apns-topic' => $message->bundleId,
            'apns-priority' => (string) $message->priority,
            'apns-expiration' => (string) (Carbon::now()->getTimestamp() + ConnPush::ExpirationSeconds),
            'apns-collapse-id' => $message->collapseId,
        ];
    }
}
