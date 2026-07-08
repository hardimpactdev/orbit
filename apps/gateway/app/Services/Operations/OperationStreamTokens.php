<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 */
final readonly class OperationStreamTokens
{
    /**
     * @return array{token: string, expires_at: Carbon}
     */
    public function authToken(OperationRun $operationRun, string $channel): array
    {
        return $this->token('subscriber', $operationRun, $channel, $this->authTtlSeconds());
    }

    /**
     * @return array{token: string, expires_at: Carbon}
     */
    public function publisherToken(OperationRun $operationRun, string $channel): array
    {
        return $this->token('publisher', $operationRun, $channel, $this->publisherTtlSeconds());
    }

    /**
     * @return array{valid: bool, expired: bool}
     */
    public function verify(
        #[SensitiveParameter]
        string $token,
        OperationRun $operationRun,
        string $channel,
        string $purpose,
    ): array {
        $parts = explode(separator: '.', string: $token, limit: 2);

        if (count($parts) !== 2) {
            return ['valid' => false, 'expired' => false];
        }

        [$payload, $signature] = $parts;

        if (! hash_equals($this->sign($payload), $signature)) {
            return ['valid' => false, 'expired' => false];
        }

        $decoded = $this->decodePayload($payload);

        if ($decoded === null) {
            return ['valid' => false, 'expired' => false];
        }

        $decodedPurpose = $this->stringClaim($decoded, 'purpose');

        if ($decodedPurpose === null || ! hash_equals($purpose, $decodedPurpose)) {
            return ['valid' => false, 'expired' => false];
        }

        $operationUuid = $this->stringClaim($decoded, 'operation_uuid');

        if ($operationUuid === null || ! hash_equals($operationRun->id, $operationUuid)) {
            return ['valid' => false, 'expired' => false];
        }

        $decodedChannel = $this->stringClaim($decoded, 'channel');

        if ($decodedChannel === null || ! hash_equals($channel, $decodedChannel)) {
            return ['valid' => false, 'expired' => false];
        }

        if (
            $purpose === 'publisher'
            && $this->integerClaim($decoded, 'target_node_id') !== $operationRun->target_node_id
        ) {
            return ['valid' => false, 'expired' => false];
        }

        $expiresAt = $this->integerClaim($decoded, 'expires_at');

        if ($expiresAt === null) {
            return ['valid' => false, 'expired' => false];
        }

        if (Carbon::createFromTimestamp($expiresAt)->lessThanOrEqualTo(Carbon::now())) {
            return ['valid' => false, 'expired' => true];
        }

        return ['valid' => true, 'expired' => false];
    }

    /**
     * @return array{token: string, expires_at: Carbon}
     */
    private function token(string $purpose, OperationRun $operationRun, string $channel, int $ttlSeconds): array
    {
        $expiresAt = Carbon::now()->addSeconds($ttlSeconds);
        $payload = base64_encode(json_encode([
            'id' => (string) Str::uuid(),
            'purpose' => $purpose,
            'operation_uuid' => $operationRun->id,
            'channel' => $channel,
            'target_node_id' => $operationRun->target_node_id,
            'expires_at' => $expiresAt->timestamp,
        ], JSON_THROW_ON_ERROR));

        return [
            'token' => "{$payload}.{$this->sign($payload)}",
            'expires_at' => $expiresAt,
        ];
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePayload(string $payload): ?array
    {
        $decoded = json_decode((string) base64_decode($payload, strict: true), associative: true);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function stringClaim(array $claims, string $key): ?string
    {
        if (! is_string($claims[$key] ?? null)) {
            return null;
        }

        return $claims[$key];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function integerClaim(array $claims, string $key): ?int
    {
        if (! is_int($claims[$key] ?? null)) {
            return null;
        }

        return $claims[$key];
    }

    private function secret(): string
    {
        $secret = Config::get('orbit.operation_token_secret');

        if (is_string($secret) && $secret !== '') {
            return $secret;
        }

        $appKey = Config::get('app.key');

        if (is_string($appKey) && $appKey !== '') {
            return $appKey;
        }

        throw new RuntimeException('Operation stream token signing requires orbit.operation_token_secret or app.key.');
    }

    private function authTtlSeconds(): int
    {
        return max(1, $this->integerConfig('orbit.operations.stream_auth_ttl_seconds', 300));
    }

    private function publisherTtlSeconds(): int
    {
        return max(1, $this->integerConfig('orbit.operations.publisher_token_ttl_seconds', 120));
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = Config::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
