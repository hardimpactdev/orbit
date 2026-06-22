<?php

declare(strict_types=1);

namespace App\Commands\Manifest;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class ManifestUpdateCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'manifest:update
        {url : Release manifest URL for update and update:all}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Set the gateway release manifest URL.';

    public function handle(): int
    {
        $url = $this->stringArgument('url');

        if ($url === null) {
            return $this->renderFailure(
                'validation_failed',
                'Manifest URL is required.',
                ['field' => 'url'],
            );
        }

        try {
            $response = $this->gatewayPut('/api/manifest', ['url' => $url]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderManifestSource($response, 'Release manifest URL updated.');
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderManifestSource(array $response, string $message): int
    {
        $manifest = $this->manifestPayload($response);

        $this->line($message);
        $this->line('Source: '.($manifest['source'] ?? 'custom'));
        $this->line('URL: '.($manifest['url'] ?? $this->stringArgument('url') ?? ''));

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
