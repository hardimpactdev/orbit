<?php

declare(strict_types=1);

namespace Orbit\Core\Security;

use InvalidArgumentException;

final class OperationToken
{
    private const SEGMENT_COUNT = 6;

    public function __construct(
        public readonly string $id,
        public readonly string $node,
        public readonly string $command,
        public readonly int $issued_at,
        public readonly int $expires_at,
        public readonly string $signature,
    ) {}

    public function toString(): string
    {
        return implode('.', [
            self::base64UrlEncode($this->id),
            self::base64UrlEncode($this->node),
            self::base64UrlEncode($this->command),
            self::base64UrlEncode((string) $this->issued_at),
            self::base64UrlEncode((string) $this->expires_at),
            self::base64UrlEncode($this->signature),
        ]);
    }

    public static function parse(string $compact): self
    {
        if ($compact === '') {
            throw new InvalidArgumentException('Operation token is empty.');
        }

        $segments = explode('.', $compact);

        if (count($segments) !== self::SEGMENT_COUNT) {
            throw new InvalidArgumentException('Operation token has an invalid segment count.');
        }

        $id = self::decodeStringSegment($segments[0], 'id');
        $node = self::decodeStringSegment($segments[1], 'node');
        $command = self::decodeStringSegment($segments[2], 'command');
        $issuedAt = self::decodeTimestampSegment($segments[3], 'issued_at');
        $expiresAt = self::decodeTimestampSegment($segments[4], 'expires_at');
        $signature = self::decodeStringSegment($segments[5], 'signature');

        if (! self::isBase64Url($signature)) {
            throw new InvalidArgumentException('Operation token signature is malformed.');
        }

        return new self(
            id: $id,
            node: $node,
            command: $command,
            issued_at: $issuedAt,
            expires_at: $expiresAt,
            signature: $signature,
        );
    }

    private static function decodeStringSegment(string $segment, string $field): string
    {
        $decoded = self::base64UrlDecode($segment, $field);

        if ($decoded === '') {
            throw new InvalidArgumentException("Operation token {$field} is empty.");
        }

        return $decoded;
    }

    private static function decodeTimestampSegment(string $segment, string $field): int
    {
        $decoded = self::decodeStringSegment($segment, $field);

        if (! ctype_digit($decoded)) {
            throw new InvalidArgumentException("Operation token {$field} is malformed.");
        }

        return (int) $decoded;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value, string $field): string
    {
        if (! self::isBase64Url($value)) {
            throw new InvalidArgumentException("Operation token {$field} is malformed.");
        }

        $decoded = base64_decode(self::withBase64Padding($value), true);

        if ($decoded === false) {
            throw new InvalidArgumentException("Operation token {$field} is malformed.");
        }

        return $decoded;
    }

    private static function isBase64Url(string $value): bool
    {
        return $value !== ''
            && preg_match('/^[A-Za-z0-9_-]+$/', $value) === 1
            && strlen($value) % 4 !== 1;
    }

    private static function withBase64Padding(string $value): string
    {
        $base64 = strtr($value, '-_', '+/');
        $padding = (4 - strlen($base64) % 4) % 4;

        return $base64.str_repeat('=', $padding);
    }
}
