<?php

namespace HardImpact\Orbit\Core\Http\Integrations\Orbit;

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
            'curl' => [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            ],
        ];
    }

    /**
     * Create a connector for the given environment.
     *
     * In local/development mode, uses orbit-web.{tld} (development instance).
     * In production mode (bundled in CLI), uses orbit.{tld} (bundled instance).
     */
    public static function forEnvironment(string $tld, int $timeout = 30): self
    {
        $subdomain = app()->environment('local') ? 'orbit-web' : 'orbit';

        return new self("https://{$subdomain}.{$tld}/api", $timeout);
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
