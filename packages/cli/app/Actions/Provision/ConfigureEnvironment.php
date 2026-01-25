<?php

declare(strict_types=1);

namespace App\Actions\Provision;

use App\Data\Provision\ProvisionContext;
use App\Data\Provision\StepResult;
use App\Services\ProvisionLogger;
use App\Services\ServiceManager;

final readonly class ConfigureEnvironment
{
    public function handle(ProvisionContext $context, ProvisionLogger $logger, ?ServiceManager $serviceManager = null): StepResult
    {
        $envExamplePath = "{$context->projectPath}/.env.example";
        $envPath = "{$context->projectPath}/.env";

        // Copy .env.example to .env if needed
        if (file_exists($envExamplePath) && ! file_exists($envPath)) {
            $logger->info('Copying .env.example to .env');
            if (! copy($envExamplePath, $envPath)) {
                return StepResult::failed('Failed to copy .env.example to .env');
            }
        }

        if (! file_exists($envPath)) {
            $logger->info('No .env file found, skipping environment configuration');

            return StepResult::success();
        }

        $logger->info('Configuring environment...');
        $env = file_get_contents($envPath);
        $logger->log('.env file size before: '.strlen($env).' bytes');

        // Configure APP_NAME
        $appName = $context->displayName ?: ucwords(str_replace('-', ' ', $context->slug));
        $env = $this->setEnvValue($env, 'APP_NAME', $appName);
        $logger->log("Set APP_NAME to: {$appName}");

        // Configure APP_URL
        $appUrl = "https://{$context->slug}.{$context->tld}";
        $env = preg_replace('/^APP_URL=.*/m', "APP_URL={$appUrl}", $env);
        $logger->log("Set APP_URL to: {$appUrl}");

        // Database configuration
        if ($context->dbDriver === 'pgsql') {
            // Get PostgreSQL credentials from services config
            $pgConfig = $serviceManager?->getService('postgres') ?? [];
            $pgUser = $pgConfig['POSTGRES_USER'] ?? 'orbit';
            $pgPassword = $pgConfig['POSTGRES_PASSWORD'] ?? 'secret';
            $pgPort = $pgConfig['port'] ?? 5432;

            $env = $this->setEnvValue($env, 'DB_CONNECTION', 'pgsql');
            $env = $this->setEnvValue($env, 'DB_HOST', '127.0.0.1');
            $env = $this->setEnvValue($env, 'DB_PORT', (string) $pgPort);
            $env = $this->setEnvValue($env, 'DB_DATABASE', $context->slug);
            $env = $this->setEnvValue($env, 'DB_USERNAME', $pgUser);
            $env = $this->setEnvValue($env, 'DB_PASSWORD', $pgPassword);
            $logger->log("Configured PostgreSQL database: {$context->slug}");
        } elseif ($context->dbDriver === 'sqlite') {
            $env = $this->setEnvValue($env, 'DB_CONNECTION', 'sqlite');
            $this->createSqliteDatabase($context, $logger);
            $logger->log('Configured SQLite database');
        }

        // Session driver
        if ($context->sessionDriver) {
            $env = $this->setEnvValue($env, 'SESSION_DRIVER', $context->sessionDriver);
            $logger->log("Set SESSION_DRIVER to: {$context->sessionDriver}");
        }

        // Cache driver
        if ($context->cacheDriver) {
            $env = $this->setEnvValue($env, 'CACHE_STORE', $context->cacheDriver);
            $logger->log("Set CACHE_STORE to: {$context->cacheDriver}");
        }

        // Queue driver
        if ($context->queueDriver) {
            $env = $this->setEnvValue($env, 'QUEUE_CONNECTION', $context->queueDriver);
            $logger->log("Set QUEUE_CONNECTION to: {$context->queueDriver}");
        }

        // Configure Redis if needed
        $needsRedis = in_array('redis', [
            $context->sessionDriver,
            $context->cacheDriver,
            $context->queueDriver,
        ], true);

        if ($needsRedis) {
            $env = $this->setEnvValue($env, 'REDIS_HOST', '127.0.0.1');
            $env = $this->setEnvValue($env, 'REDIS_PORT', '6379');
            $logger->log('Configured Redis connection');
        }

        // Write the configured .env
        $bytesWritten = file_put_contents($envPath, $env);
        if ($bytesWritten === false) {
            return StepResult::failed('Failed to write .env file');
        }

        $logger->log(".env file size after: {$bytesWritten} bytes");
        $logger->info('Environment configured successfully');

        return StepResult::success();
    }

    private function setEnvValue(string $env, string $key, string $value): string
    {
        // Escape value if it contains spaces or special characters
        if (preg_match("/[\s#]/", $value)) {
            $value = '"'.$value.'"';
        }

        // Check if key exists
        if (preg_match("/^{$key}=.*/m", $env)) {
            return preg_replace("/^{$key}=.*/m", "{$key}={$value}", $env);
        }

        // Add new key at end
        return rtrim($env)."\n{$key}={$value}\n";
    }

    private function createSqliteDatabase(ProvisionContext $context, ProvisionLogger $logger): void
    {
        $sqlitePath = "{$context->projectPath}/database/database.sqlite";

        if (file_exists($sqlitePath)) {
            $logger->log('SQLite database already exists');

            return;
        }

        $dir = dirname($sqlitePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        touch($sqlitePath);
        $logger->log('Created SQLite database file');
    }
}
