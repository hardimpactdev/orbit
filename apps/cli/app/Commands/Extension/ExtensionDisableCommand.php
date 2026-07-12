<?php

declare(strict_types=1);

namespace App\Commands\Extension;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\Extensions\LocalExtensionState;
use Orbit\Core\Extensions\OrbitExtensionRegistry;

final class ExtensionDisableCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'extension:disable
        {extension : Extension slug to disable}
        {--node= : Disable the extension on a specific node target}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Disable an Orbit extension locally and optionally on the gateway.';

    public function handle(): int
    {
        $slug = $this->stringArgument('extension');

        if ($slug === null) {
            return $this->renderFailure(
                'validation_failed',
                'Extension slug is required.',
                ['field' => 'extension'],
            );
        }

        if (app(OrbitExtensionRegistry::class)->get($slug) === null) {
            return $this->renderFailure(
                'extension_unknown',
                "Unknown Orbit extension [{$slug}].",
                ['extension' => $slug],
            );
        }

        $node = $this->stringOption('node');

        if ($node !== null) {
            if ($node !== 'gateway') {
                return $this->renderFailure(
                    'extension_node_target_unsupported',
                    "Extension node target [{$node}] is not supported.",
                    ['node' => $node],
                );
            }

            return $this->disableOnGateway($slug);
        }

        return $this->disableLocally($slug);
    }

    private function disableLocally(string $slug): int
    {
        app(LocalExtensionState::class)->disable($slug);

        return $this->renderSuccess([
            'extension' => [
                'slug' => $slug,
                'local_enabled' => false,
            ],
        ]);
    }

    private function disableOnGateway(string $slug): int
    {
        try {
            $response = $this->gatewayPost('/api/extensions/'.rawurlencode($slug).'/disable');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
