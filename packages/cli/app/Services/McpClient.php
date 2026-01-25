<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class McpClient
{
    private readonly string $baseUrl;

    private ?string $resolveHost = null;

    public function __construct(ConfigManager $config)
    {
        // CLI always calls localhost Sequence
        $sequenceUrl = $config->get('sequence.url', 'http://localhost:8000');
        $this->baseUrl = rtrim((string) $sequenceUrl, '/').'/mcp';

        // Check if URL uses .ccc TLD - resolve to localhost for background processes
        $parsedUrl = parse_url($this->baseUrl);
        $host = $parsedUrl['host'] ?? null;
        if ($host && str_ends_with($host, '.ccc')) {
            $this->resolveHost = $host;
        }
    }

    public function isConfigured(): bool
    {
        return ! empty($this->baseUrl);
    }

    /**
     * Call an MCP tool. No authentication required.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function callTool(string $toolName, array $arguments = []): array
    {
        $http = Http::timeout(120);

        // Add CURL resolve option to bypass DNS for .ccc domains
        // This ensures the request works even in background processes without DNS access
        if ($this->resolveHost) {
            $http = $http->withOptions([
                'curl' => [
                    CURLOPT_RESOLVE => [
                        "{$this->resolveHost}:443:127.0.0.1",
                        "{$this->resolveHost}:80:127.0.0.1",
                    ],
                ],
            ]);
        }

        $response = $http->post($this->baseUrl, [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => $arguments,
            ],
            'id' => uniqid(),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'MCP call failed: '.($response->json('error.message') ?? $response->body())
            );
        }

        /** @var array<string, mixed> $result */
        $result = $response->json();

        if (isset($result['error'])) {
            /** @var array{message?: string} $error */
            $error = $result['error'];
            throw new RuntimeException('MCP error: '.($error['message'] ?? 'Unknown error'));
        }

        $mcpResult = $result['result'] ?? [];

        // Extract meta from content if present (MCP response format)
        if (isset($mcpResult['content'][0]['_meta'])) {
            $mcpResult['meta'] = $mcpResult['content'][0]['_meta'];
        }

        /** @var array<string, mixed> */
        return $mcpResult;
    }
}
