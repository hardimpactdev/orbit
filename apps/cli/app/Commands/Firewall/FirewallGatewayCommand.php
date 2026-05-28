<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\OrbitConfigStoreException;
use App\Services\OrbitConfigStore;

abstract class FirewallGatewayCommand extends GatewayCommand
{
    use ResolvesHostContext;

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    protected function failValidation(string $field, string $message, array $extraMeta = []): int
    {
        return $this->renderFailure('validation_failed', $message, array_merge(['field' => $field], $extraMeta));
    }

    protected function requiredArgument(string $name, string $message): string|int
    {
        $value = $this->stringArgument($name);

        if ($value === null) {
            return $this->failValidation($name, $message);
        }

        return $value;
    }

    protected function firewallTargetNode(): string|int
    {
        $node = $this->stringOption('node');

        if ($node !== null) {
            return $node;
        }

        try {
            $node = app(OrbitConfigStore::class)->defaultNode();
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        }

        if ($node === null) {
            return $this->renderFailure(
                'node_target_required',
                'A node target is required. Provide --node or set a local default with `orbit node:default`.',
                ['field' => 'node'],
            );
        }

        return $node;
    }
}
