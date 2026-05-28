<?php

declare(strict_types=1);

namespace App\Services\Profile;

use Throwable;

use function Laravel\Prompts\datatable;

final class ProfileAppSelector
{
    /**
     * @param  array<string, mixed>  $response
     */
    public function selectFromResponse(array $response): ?string
    {
        $rows = $this->rows($this->appsFromResponse($response));

        if ($rows === []) {
            return null;
        }

        try {
            $selected = datatable(
                headers: ['Host', 'Node', 'Repository'],
                rows: $rows,
                label: 'Select an app to profile',
                hint: 'Press / to search',
            );
        } catch (Throwable) {
            return null;
        }

        return is_string($selected) && $selected !== '' ? $selected : null;
    }

    /**
     * @param  list<array<string, mixed>>  $apps
     * @return array<string, array<int, string>>
     */
    private function rows(array $apps): array
    {
        $rows = [];

        foreach ($apps as $app) {
            $name = is_string($app['name'] ?? null) && $app['name'] !== '' ? $app['name'] : null;

            if ($name === null) {
                continue;
            }

            $rows[$name] = [
                $this->domainFromAppPayload($app, $name),
                is_string($app['node'] ?? null) && $app['node'] !== '' ? $app['node'] : '-',
                is_string($app['repository'] ?? null) && $app['repository'] !== '' ? $app['repository'] : '-',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function appsFromResponse(array $response): array
    {
        $success = is_array($response['success'] ?? null) ? $response['success'] : [];
        $data = is_array($success['data'] ?? null) ? $success['data'] : [];
        $apps = is_array($data['apps'] ?? null) ? $data['apps'] : [];

        return array_values(array_filter($apps, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $app
     */
    private function domainFromAppPayload(array $app, string $fallback): string
    {
        if (is_string($app['domain'] ?? null) && $app['domain'] !== '') {
            return $app['domain'];
        }

        if (is_string($app['url'] ?? null)) {
            $host = parse_url($app['url'], PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                return $host;
            }
        }

        return $fallback;
    }
}
