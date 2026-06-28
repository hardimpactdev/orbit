<?php

declare(strict_types=1);

namespace App\Commands\Extension;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\Extensions\LocalExtensionState;
use Orbit\Core\Extensions\OrbitExtensionDefinition;
use Orbit\Core\Extensions\OrbitExtensionRegistry;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final class ExtensionListCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'extension:list
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'List Orbit extensions and their local and gateway enablement state.';

    public function handle(): int
    {
        $registry = app(OrbitExtensionRegistry::class);
        $localState = app(LocalExtensionState::class);
        $gatewayEnabledBySlug = [];
        $warnings = [];
        $gatewayAvailable = false;

        try {
            $response = $this->gatewayGet('/api/extensions');
            $gatewayEnabledBySlug = $this->gatewayEnabledBySlug($response);
            $gatewayAvailable = true;
        } catch (GatewayApiException $exception) {
            $warnings[] = [
                'code' => $exception->cliFailureCode(),
                'message' => $exception->getMessage(),
            ];
        }

        $extensions = array_map(
            static fn (OrbitExtensionDefinition $definition): array => [
                'slug' => $definition->slug,
                'label' => $definition->label,
                'description' => $definition->description,
                'commands' => $definition->commands,
                'permissions' => $definition->permissions,
                'local_enabled' => $localState->enabled($definition->slug),
                'gateway_enabled' => $gatewayAvailable
                    ? $gatewayEnabledBySlug[$definition->slug] ?? false
                    : null,
            ],
            $registry->all(),
        );

        $meta = $warnings === [] ? [] : ['warnings' => $warnings];

        if ($this->wantsJson()) {
            return $this->renderSuccess(['extensions' => $extensions], $meta);
        }

        if ($gatewayAvailable) {
            return $this->renderHumanListWithGatewayState($extensions);
        }

        return $this->renderHumanListWithoutGatewayState($extensions);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, bool>
     */
    private function gatewayEnabledBySlug(array $response): array
    {
        $success = $this->stringKeyedArray($response['success'] ?? null);
        $data = $this->stringKeyedArray($success['data'] ?? null);
        $extensions = $data['extensions'] ?? null;

        if (! is_array($extensions)) {
            return [];
        }

        $enabledBySlug = [];

        foreach ($extensions as $extension) {
            $extension = $this->stringKeyedArray($extension);

            if ($extension === []) {
                continue;
            }

            $slug = $extension['slug'] ?? null;

            if (! is_string($slug) || $slug === '') {
                continue;
            }

            $enabledBySlug[$slug] = ($extension['enabled'] ?? false) === true;
        }

        return $enabledBySlug;
    }

    /**
     * @param  list<array<string, mixed>>  $extensions
     */
    private function renderHumanListWithGatewayState(array $extensions): int
    {
        foreach ($extensions as $extension) {
            $slug = $extension['slug'] ?? null;
            $slug = is_string($slug) && $slug !== '' ? $slug : 'unknown';
            $localEnabled = ($extension['local_enabled'] ?? false) === true ? 'enabled' : 'disabled';
            $gatewayEnabled = ($extension['gateway_enabled'] ?? false) === true ? 'enabled' : 'disabled';

            $this->line("{$slug}: local={$localEnabled}, gateway={$gatewayEnabled}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $extensions
     */
    private function renderHumanListWithoutGatewayState(array $extensions): int
    {
        foreach ($extensions as $extension) {
            $slug = $extension['slug'] ?? null;
            $slug = is_string($slug) && $slug !== '' ? $slug : 'unknown';
            $localEnabled = ($extension['local_enabled'] ?? false) === true ? 'enabled' : 'disabled';

            $this->line("{$slug}: local={$localEnabled}, gateway=unknown");
        }

        return self::SUCCESS;
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
