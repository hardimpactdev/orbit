<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\Concerns\StreamsGatewayProgress;
use App\Commands\GatewayCommand;

abstract class AppGatewayCommand extends GatewayCommand
{
    use ResolvesHostContext;
    use StreamsGatewayProgress;

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    protected function failValidation(string $field, string $message, array $extraMeta = []): int
    {
        return $this->renderFailure('validation_failed', $message, [
            'field' => $field,
            ...$extraMeta,
        ]);
    }

    protected function apiInstancePath(string $selector, string $suffix = ''): string
    {
        return '/api/instances/'.rawurlencode($selector).$suffix;
    }

    protected function apiProjectPath(string $app, string $suffix = ''): string
    {
        return '/api/apps/'.rawurlencode($app).$suffix;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    protected function successData(array $response): array
    {
        $success = $response['success'] ?? null;

        if (is_array($success) && is_array($success['data'] ?? null)) {
            return $success['data'];
        }

        return $response;
    }
}
