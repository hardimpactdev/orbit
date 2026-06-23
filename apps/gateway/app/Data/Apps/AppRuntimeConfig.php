<?php

declare(strict_types=1);

namespace App\Data\Apps;

use InvalidArgumentException;

final readonly class AppRuntimeConfig
{
    public const string ProxyTransportHttp = 'http';

    public const string ProxyTransportHttps = 'https';

    public function __construct(
        public string $proxyTransport = self::ProxyTransportHttp,
    ) {
        if (! in_array($this->proxyTransport, [self::ProxyTransportHttp, self::ProxyTransportHttps], true)) {
            throw new InvalidArgumentException("App runtime config 'proxy_transport' must be 'http' or 'https'.");
        }
    }

    public static function default(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null || $data === []) {
            return self::default();
        }

        return new self(
            proxyTransport: self::parseProxyTransport($data['proxy_transport'] ?? self::ProxyTransportHttp),
        );
    }

    public static function fromProxyTransportOption(?string $value): self
    {
        if ($value === null || trim($value) === '') {
            return self::default();
        }

        return new self(proxyTransport: self::parseProxyTransport($value));
    }

    public function usesInnerHttpsProxyTransport(): bool
    {
        return $this->proxyTransport === self::ProxyTransportHttps;
    }

    /**
     * @return array{proxy_transport: string}
     */
    public function toArray(): array
    {
        return [
            'proxy_transport' => $this->proxyTransport,
        ];
    }

    private static function parseProxyTransport(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("App runtime config 'proxy_transport' must be a string.");
        }

        $normalized = strtolower(trim($value));

        if (! in_array($normalized, [self::ProxyTransportHttp, self::ProxyTransportHttps], true)) {
            throw new InvalidArgumentException("App runtime config 'proxy_transport' must be 'http' or 'https'.");
        }

        return $normalized;
    }
}
