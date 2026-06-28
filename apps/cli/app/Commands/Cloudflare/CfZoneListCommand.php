<?php

declare(strict_types=1);

namespace App\Commands\Cloudflare;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class CfZoneListCommand extends CloudflareGatewayCommand
{
    #[\Override]
    protected $signature = 'cf-zone:list {--json}';

    #[\Override]
    protected $description = 'List Cloudflare zones visible to the gateway token.';

    public function handle(): int
    {
        if (($failure = $this->guardLocalExtension()) !== null) {
            return $failure;
        }

        try {
            $response = $this->gatewayGet('/api/cloudflare/zones');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $zones = $this->zonesFromGatewayResponse($response);

        if ($zones === []) {
            $this->line('No Cloudflare zones found.');

            return self::SUCCESS;
        }

        table(
            headers: ['ZONE ID', 'DOMAIN', 'STATUS'],
            rows: array_map(fn (array $zone): array => [
                $this->zoneString($zone, 'id'),
                $this->zoneString($zone, 'name'),
                $this->zoneString($zone, 'status'),
            ], $zones),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function zonesFromGatewayResponse(array $response): array
    {
        $zones = $response['success']['data']['zones'] ?? null;

        if (! is_array($zones)) {
            return [];
        }

        return array_values(array_filter($zones, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $zone
     */
    private function zoneString(array $zone, string $key): string
    {
        $value = $zone[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }
}
