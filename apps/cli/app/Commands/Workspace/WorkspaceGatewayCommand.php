<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;

abstract class WorkspaceGatewayCommand extends GatewayCommand
{
    use ResolvesHostContext;

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    protected function failValidation(string $field, string $message, array $extraMeta = []): int
    {
        return $this->renderFailure('validation_failed', $message, array_merge(['field' => $field], $extraMeta));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function pathWithQuery(string $path, array $query): string
    {
        $filtered = $this->filledQuery($query);

        if ($filtered === []) {
            return $path;
        }

        return $path.'?'.http_build_query($filtered, '', '&', PHP_QUERY_RFC3986);
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
