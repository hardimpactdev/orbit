<?php

declare(strict_types=1);

namespace App\Commands\Extension;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\Extensions\LocalExtensionState;
use Orbit\Core\Extensions\OrbitExtensionRegistry;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class ExtensionEnableCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'extension:enable
        {extension : Extension slug to enable}
        {--node= : Enable the extension on a specific node target}
        {--gateway : Enable the extension on the gateway when it is disabled}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Enable an Orbit extension locally and optionally on the gateway.';

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

            return $this->enableGatewayOnly($slug);
        }

        return $this->enableLocally($slug);
    }

    private function enableLocally(string $slug): int
    {
        app(LocalExtensionState::class)->enable($slug);

        $gatewayEnabled = $this->gatewayEnabledFor($slug);

        if ($gatewayEnabled !== false) {
            return $this->renderSuccess([
                'extension' => [
                    'slug' => $slug,
                    'local_enabled' => true,
                    'gateway_enabled' => $gatewayEnabled,
                ],
            ]);
        }

        if ($this->option('gateway') === true) {
            return $this->enableGatewayAfterLocalEnable($slug);
        }

        if ($this->allowsInteractiveInput()) {
            if ($this->confirm("Enable extension [{$slug}] on the gateway too?", default: true)) {
                return $this->enableGatewayAfterLocalEnable($slug);
            }

            return $this->renderSuccess([
                'extension' => [
                    'slug' => $slug,
                    'local_enabled' => true,
                    'gateway_enabled' => false,
                ],
            ]);
        }

        return $this->renderFailure(
            'extension_gateway_enable_required',
            "Gateway extension [{$slug}] is disabled. Pass --gateway to enable it.",
            ['extension' => $slug],
        );
    }

    private function enableGatewayOnly(string $slug): int
    {
        try {
            $response = $this->gatewayPost('/api/extensions/'.rawurlencode($slug).'/enable');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    private function enableGatewayAfterLocalEnable(string $slug): int
    {
        try {
            $response = $this->gatewayPost('/api/extensions/'.rawurlencode($slug).'/enable');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $data = $this->successData($response);
        $extension = $this->stringKeyedArray($data['extension'] ?? null);
        $extension['local_enabled'] = true;

        return $this->renderSuccess(['extension' => $extension], $this->successMeta($response));
    }

    private function gatewayEnabledFor(string $slug): ?bool
    {
        try {
            $response = $this->gatewayGet('/api/extensions');
        } catch (GatewayApiException) {
            return null;
        }

        $extensions = $this->successExtensions($response);

        if ($extensions === null) {
            return null;
        }

        foreach ($extensions as $extension) {
            if (($extension['slug'] ?? null) !== $slug) {
                continue;
            }

            return ($extension['enabled'] ?? false) === true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successData(array $response): array
    {
        $success = $this->stringKeyedArray($response['success'] ?? null);

        return $this->stringKeyedArray($success['data'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>|null
     */
    private function successExtensions(array $response): ?array
    {
        $data = $this->successData($response);
        $extensions = $data['extensions'] ?? null;

        if (! is_array($extensions)) {
            return null;
        }

        /** @var list<array<string, mixed>> $typedExtensions */
        $typedExtensions = [];

        foreach ($extensions as $extension) {
            $extension = $this->stringKeyedArray($extension);

            if ($extension === []) {
                continue;
            }

            $typedExtensions[] = $extension;
        }

        return $typedExtensions;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successMeta(array $response): array
    {
        $success = $this->stringKeyedArray($response['success'] ?? null);

        return $this->stringKeyedArray($success['meta'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $result */
        $result = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            $result[$key] = $item;
        }

        return $result;
    }
}
