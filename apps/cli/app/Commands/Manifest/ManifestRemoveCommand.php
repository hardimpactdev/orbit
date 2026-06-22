<?php

declare(strict_types=1);

namespace App\Commands\Manifest;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class ManifestRemoveCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'manifest:remove {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove the custom gateway release manifest URL.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayDelete('/api/manifest');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderManifestSource($response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderManifestSource(array $response): int
    {
        $manifest = $this->manifestPayload($response);

        $this->line('Release manifest URL removed.');
        $this->line('Source: '.($manifest['source'] ?? 'default'));
        $this->line('URL: '.($manifest['url'] ?? ''));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function manifestPayload(array $response): array
    {
        $manifest = $response['success']['data']['manifest'] ?? null;

        return is_array($manifest) ? $manifest : [];
    }
}
