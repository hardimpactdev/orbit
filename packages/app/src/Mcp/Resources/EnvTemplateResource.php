<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Ui\Mcp\Resources;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

class EnvTemplateResource extends Resource implements HasUriTemplate
{
    protected string $mimeType = 'text/plain';

    public function __construct(protected ConfigurationService $configService) {}

    public function name(): string
    {
        return 'env-template';
    }

    public function title(): string
    {
        return 'Environment Templates';
    }

    public function description(): string
    {
        return 'Environment variable templates for Laravel projects. Types: database, redis, mail, broadcasting, full.';
    }

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('orbit://env-template/{type}');
    }

    public function handle(Request $request): Response
    {
        $type = $request->get('type', 'full');

        $templates = [
            'database' => $this->getDatabaseTemplate(),
            'redis' => $this->getRedisTemplate(),
            'mail' => $this->getMailTemplate(),
            'broadcasting' => $this->getBroadcastingTemplate(),
            'full' => $this->getFullTemplate(),
        ];

        if (! isset($templates[$type])) {
            return Response::error("Invalid template type: {$type}. Available types: database, redis, mail, broadcasting, full");
        }

        return Response::text($templates[$type]);
    }

    protected function getDatabaseTemplate(): string
    {
        return <<<'ENV'
# Database
DB_CONNECTION=pgsql
DB_HOST=orbit-postgres
DB_PORT=5432
DB_DATABASE=your_database_name
DB_USERNAME=orbit
DB_PASSWORD=orbit
ENV;
    }

    protected function getRedisTemplate(): string
    {
        return <<<'ENV'
# Redis
REDIS_HOST=orbit-redis
REDIS_PASSWORD=null
REDIS_PORT=6379

# Cache
CACHE_STORE=redis
CACHE_PREFIX=

# Session
SESSION_DRIVER=redis

# Queue
QUEUE_CONNECTION=redis
ENV;
    }

    protected function getMailTemplate(): string
    {
        return <<<'ENV'
# Mail (Mailpit)
MAIL_MAILER=smtp
MAIL_HOST=orbit-mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"
ENV;
    }

    protected function getBroadcastingTemplate(): string
    {
        $reverbConfig = $this->getReverbConfig();

        return <<<ENV
# Broadcasting (Reverb)
BROADCAST_CONNECTION=reverb

REVERB_APP_ID={$reverbConfig['app_id']}
REVERB_APP_KEY={$reverbConfig['app_key']}
REVERB_APP_SECRET={$reverbConfig['app_secret']}
REVERB_HOST={$reverbConfig['host']}
REVERB_PORT={$reverbConfig['port']}
REVERB_SCHEME=https

VITE_REVERB_APP_KEY="\${REVERB_APP_KEY}"
VITE_REVERB_HOST="\${REVERB_HOST}"
VITE_REVERB_PORT="\${REVERB_PORT}"
VITE_REVERB_SCHEME="\${REVERB_SCHEME}"
ENV;
    }

    protected function getFullTemplate(): string
    {
        return implode("\n\n", [
            $this->getDatabaseTemplate(),
            $this->getRedisTemplate(),
            $this->getMailTemplate(),
            $this->getBroadcastingTemplate(),
        ]);
    }

    protected function getReverbConfig(): array
    {
        $environment = Environment::getLocal();

        if ($environment) {
            $result = $this->configService->getReverbConfig($environment);
            if ($result['success'] && $result['enabled']) {
                return [
                    'app_id' => '1',
                    'app_key' => $result['app_key'] ?? 'orbit-key',
                    'app_secret' => 'orbit-secret',
                    'host' => $result['host'] ?? 'reverb.test',
                    'port' => $result['port'] ?? 443,
                ];
            }
        }

        // Default values
        return [
            'app_id' => '1',
            'app_key' => 'orbit-key',
            'app_secret' => 'orbit-secret',
            'host' => 'reverb.test',
            'port' => 443,
        ];
    }
}
