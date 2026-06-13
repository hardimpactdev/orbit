<?php

declare(strict_types=1);

namespace App\Commands\S3;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class S3CredentialsCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 's3:credentials
        {--node= : Active s3 node to read credentials for}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Show SeaweedFS service credentials and endpoint metadata.';

    public function handle(): int
    {
        $node = $this->resolveNode();

        if (is_int($node)) {
            return $node;
        }

        try {
            $response = $this->gatewayGet('/api/s3/credentials', $this->filledQuery([
                'node' => $node,
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderCredentialsJson($response);
        }

        return $this->renderCredentialsTable($response);
    }

    /**
     * Resolve the target s3 node.
     *
     * --node defaults to the single visible active s3 node when exactly one exists.
     * In non-interactive mode (or --json) when multiple nodes exist, fail with
     * validation_failed field=node required_role=s3.
     *
     * @return string|int Node name, or int exit code on failure.
     */
    private function resolveNode(): string|int
    {
        $node = $this->stringOption('node');

        if ($node !== null) {
            return $node;
        }

        // Fetch active s3 nodes from the gateway to auto-resolve or prompt.
        try {
            $response = $this->gatewayGet('/api/nodes', ['role' => 's3']);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $nodes = $this->extractNodeNames($response);

        if ($nodes === []) {
            return $this->renderFailure(
                'validation_failed',
                'An active s3 role node is required.',
                ['field' => 'node', 'required_role' => 's3'],
            );
        }

        if (count($nodes) === 1) {
            return $nodes[0];
        }

        // Multiple nodes: fail non-interactively (s3:credentials has no interactive prompt).
        return $this->renderFailure(
            'validation_failed',
            'An active s3 role node is required.',
            ['field' => 'node', 'required_role' => 's3'],
        );
    }

    /**
     * Extract node names from the gateway node list response.
     *
     * @return list<string>
     */
    private function extractNodeNames(array $response): array
    {
        $nodes = $response['success']['data']['nodes'] ?? [];

        if (! is_array($nodes)) {
            return [];
        }

        $names = [];

        foreach ($nodes as $node) {
            if (is_array($node) && is_string($node['name'] ?? null) && $node['name'] !== '') {
                $names[] = $node['name'];
            }
        }

        return $names;
    }

    /**
     * Render the credentials payload as a JSON success envelope.
     *
     * @param  array<string, mixed>  $response
     */
    private function renderCredentialsJson(array $response): int
    {
        $data = $this->extractSuccessData($response);
        $credentials = is_array($data['credentials'] ?? null) ? $data['credentials'] : [];

        return $this->renderSuccess(['credentials' => $credentials], $this->extractMeta($response));
    }

    /**
     * Render the credentials payload as a human-readable table.
     *
     * @param  array<string, mixed>  $response
     */
    private function renderCredentialsTable(array $response): int
    {
        $data = $this->extractSuccessData($response);
        $credentials = is_array($data['credentials'] ?? null) ? $data['credentials'] : [];

        $node = is_string($credentials['node'] ?? null) ? $credentials['node'] : '';
        $privateEndpoint = is_string($credentials['private_endpoint'] ?? null) ? $credentials['private_endpoint'] : '';
        $publicEndpoints = is_array($credentials['public_endpoints'] ?? null)
            ? implode(', ', array_filter($credentials['public_endpoints'], is_string(...)))
            : '';
        $region = is_string($credentials['region'] ?? null) ? $credentials['region'] : '';
        $accessKeyId = is_string($credentials['access_key_id'] ?? null) ? $credentials['access_key_id'] : '';
        $secretAccessKey = is_string($credentials['secret_access_key'] ?? null) ? $credentials['secret_access_key'] : '';
        $bucketStyle = is_string($credentials['bucket_endpoint_style'] ?? null) ? $credentials['bucket_endpoint_style'] : '';
        $backendPool = is_array($credentials['backend_pool'] ?? null)
            ? implode(', ', array_filter($credentials['backend_pool'], is_string(...)))
            : '';

        table(
            ['Field', 'Value'],
            [
                ['Node', $node],
                ['Private Endpoint', $privateEndpoint],
                ['Public Endpoints', $publicEndpoints],
                ['Region', $region],
                ['Access Key ID', $accessKeyId],
                ['Secret Access Key', $secretAccessKey],
                ['Endpoint Style', $bucketStyle],
                ['Backend Pool', $backendPool],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function extractSuccessData(array $response): array
    {
        $success = $response['success'] ?? null;

        if (is_array($success) && is_array($success['data'] ?? null)) {
            return $success['data'];
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function extractMeta(array $response): array
    {
        $success = $response['success'] ?? null;

        if (is_array($success) && is_array($success['meta'] ?? null)) {
            return $success['meta'];
        }

        return [];
    }
}
