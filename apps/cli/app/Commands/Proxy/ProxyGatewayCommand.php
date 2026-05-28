<?php

declare(strict_types=1);

namespace App\Commands\Proxy;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\OrbitConfigStoreException;
use App\Services\OrbitConfigStore;

use function Laravel\Prompts\text;

abstract class ProxyGatewayCommand extends GatewayCommand
{
    use ResolvesHostContext;

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    protected function failValidation(string $field, string $message, array $extraMeta = []): int
    {
        return $this->renderFailure('validation_failed', $message, array_merge(['field' => $field], $extraMeta));
    }

    protected function resolveServingNode(): string|int
    {
        try {
            $node = $this->stringOption('node') ?? app(OrbitConfigStore::class)->defaultNode();
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        }

        if ($node !== null) {
            return $node;
        }

        if ($this->isInteractiveInput()) {
            return trim(text(label: 'Serving node', required: true));
        }

        return $this->renderFailure(
            'node_target_required',
            'A node target is required. Provide --node or set a local default with `orbit node:default`.',
            ['field' => 'node'],
        );
    }

    protected function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    protected function scalarOption(string $key): ?string
    {
        $value = $this->option($key);

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
