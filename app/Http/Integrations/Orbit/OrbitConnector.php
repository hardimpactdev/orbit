<?php

namespace App\Http\Integrations\Orbit;

use Saloon\Http\Connector;
use Saloon\Http\Faking\MockClient;
use Saloon\Traits\Plugins\AcceptsJson;

class OrbitConnector extends Connector
{
    use AcceptsJson;

    /**
     * Static mock client for testing.
     */
    protected static ?MockClient $testingMockClient = null;

    public function __construct(
        protected string $baseUrl,
        protected int $timeout = 30,
    ) {
        // Apply static mock client if set (for testing)
        if (self::$testingMockClient instanceof MockClient) {
            $this->withMockClient(self::$testingMockClient);
        }
    }

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }

    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    protected function defaultConfig(): array
    {
        return [
            'timeout' => $this->timeout,
            'verify' => false, // Allow self-signed certificates
        ];
    }

    /**
     * Create a connector for the given environment.
     */
    public static function forEnvironment(string $tld, int $timeout = 30): self
    {
        return new self("https://orbit.{$tld}/api", $timeout);
    }

    /**
     * Set a mock client for testing (static, applies to all instances).
     */
    public static function setMockClient(?MockClient $mockClient): void
    {
        self::$testingMockClient = $mockClient;
    }

    /**
     * Clear the static mock client.
     */
    public static function clearMockClient(): void
    {
        self::$testingMockClient = null;
    }
}
