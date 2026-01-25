<?php

declare(strict_types=1);

namespace App\Actions\Provision;

use App\Data\Provision\ProvisionContext;
use App\Data\Provision\StepResult;
use App\Services\ProvisionLogger;

final readonly class ConfigureTrustedProxies
{
    public function handle(ProvisionContext $context, ProvisionLogger $logger): StepResult
    {
        $bootstrapPath = "{$context->projectPath}/bootstrap/app.php";

        if (! file_exists($bootstrapPath)) {
            $logger->log('No bootstrap/app.php found, skipping trusted proxies');

            return StepResult::success();
        }

        $content = file_get_contents($bootstrapPath);

        // Check if this is Laravel 11+ (uses Application::configure)
        if (! str_contains($content, 'Application::configure')) {
            $logger->info('Skipping trusted proxies (not Laravel 11+)');

            return StepResult::success();
        }

        // Check if trusted proxies already configured
        if (str_contains($content, 'trustProxies')) {
            $logger->info('Trusted proxies already configured');

            return StepResult::success();
        }

        $logger->info('Configuring trusted proxies for reverse proxy support...');

        // Add the Request import if not present
        if (! str_contains($content, 'use Illuminate\\Http\\Request')) {
            $content = str_replace(
                'use Illuminate\\Foundation\\Application;',
                "use Illuminate\\Foundation\\Application;\nuse Illuminate\\Http\\Request;",
                $content
            );
        }

        // Add trusted proxies configuration to withMiddleware
        $trustedProxiesCode = '$middleware->trustProxies(at: "*", headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);';

        // Pattern for empty middleware callback
        $emptyPattern = '/->withMiddleware\(function\s*\(Middleware\s+\$middleware\)\s*:\s*void\s*\{\s*\/\/\s*\}\)/s';
        if (preg_match($emptyPattern, $content)) {
            $content = preg_replace(
                $emptyPattern,
                "->withMiddleware(function (Middleware \$middleware): void {\n        {$trustedProxiesCode}\n    })",
                $content
            );
        } else {
            // Pattern for middleware callback with existing content - add at the beginning
            $middlewarePattern = '/->withMiddleware\(function\s*\(Middleware\s+\$middleware\)\s*:\s*void\s*\{/s';
            if (preg_match($middlewarePattern, $content)) {
                $content = preg_replace(
                    $middlewarePattern,
                    "->withMiddleware(function (Middleware \$middleware): void {\n        {$trustedProxiesCode}\n",
                    $content
                );
            }
        }

        $bytesWritten = file_put_contents($bootstrapPath, $content);
        if ($bytesWritten === false) {
            return StepResult::failed('Failed to write bootstrap/app.php');
        }

        $logger->info('Configured trusted proxies for reverse proxy support');

        return StepResult::success();
    }
}
