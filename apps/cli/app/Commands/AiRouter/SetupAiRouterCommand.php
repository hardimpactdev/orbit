<?php

declare(strict_types=1);

namespace App\Commands\AiRouter;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\AiRouter\SetupAiRouterRequest;
use Orbit\Sdk\Responses\AiRouter\AiRouterStatusResponse;

final class SetupAiRouterCommand extends AiRouterCommand
{
    #[\Override]
    protected $signature = 'ai-router:setup
        {--node= : Node ID or name that already hosts CLIProxyAPI}
        {--cache-connection= : Redis Database connection slug for shared Valkey}
        {--cliproxy-url= : CLIProxyAPI Management API origin}
        {--cliproxy-management-key-file= : File that contains only the management key}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Deploy the collector and publish collector.ai-router.orbit.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $node = $this->stringOption('node');
        $nodeId = $this->resolveNodeId($connector, $node);

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $cacheConnection = $this->stringOption('cache-connection');

        if ($cacheConnection === null) {
            return $this->renderGatewayFailure(
                'ai_router.cache_missing',
                'A Redis Database connection slug is required.',
                details: ['field' => 'cache-connection'],
            );
        }

        $cliproxyUrl = $this->stringOption('cliproxy-url');

        if ($cliproxyUrl === null || filter_var($cliproxyUrl, FILTER_VALIDATE_URL) === false) {
            return $this->renderGatewayFailure(
                'ai_router.url_invalid',
                'CLIProxyAPI Management API origin must be an HTTP or HTTPS URL.',
                details: ['field' => 'cliproxy-url'],
            );
        }

        $key = $this->managementKey();

        if ($key === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new SetupAiRouterRequest($nodeId, $cacheConnection, $cliproxyUrl, $key),
            AiRouterStatusResponse::class,
            ['Setup ai-router', 'Deploying the collector', 'Set up ai-router'],
        );

        return $response instanceof AiRouterStatusResponse
            ? $this->renderStatus($response, 'ai-router is enabled.')
            : self::FAILURE;
    }

    private function managementKey(): ?string
    {
        $path = $this->stringOption('cliproxy-management-key-file');

        if ($path === null) {
            $this->renderGatewayFailure(
                'ai_router.key_required',
                'Supply --cliproxy-management-key-file. The CLI never accepts the key on the command line.',
                details: ['field' => 'cliproxy-management-key-file'],
            );

            return null;
        }

        if (! is_file($path) || ! is_readable($path)) {
            $this->renderGatewayFailure(
                'ai_router.key_invalid',
                'The management key file is unreadable.',
                details: ['field' => 'cliproxy-management-key-file'],
            );

            return null;
        }

        $key = trim((string) file_get_contents($path));

        if ($key === '') {
            $this->renderGatewayFailure(
                'ai_router.key_invalid',
                'The management key file is empty.',
                details: ['field' => 'cliproxy-management-key-file'],
            );

            return null;
        }

        return $key;
    }
}
