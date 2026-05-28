<?php

declare(strict_types=1);

namespace App\Commands\Tool;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\OrbitConfigStoreException;
use App\Services\OrbitConfigStore;

abstract class ToolGatewayCommand extends GatewayCommand
{
    use ResolvesHostContext;

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    protected function failValidation(string $field, string $message, array $extraMeta = []): int
    {
        return $this->renderFailure('validation_failed', $message, array_merge(['field' => $field], $extraMeta));
    }

    protected function requireToolArgument(string $message = 'A tool name is required.'): string|int
    {
        $tool = $this->stringArgument('tool');

        if ($tool === null) {
            return $this->failValidation('tool', $message);
        }

        return $tool;
    }

    /**
     * @return array<string, string>|int
     */
    protected function toolTargetPayload(bool $requireTarget = false): array|int
    {
        $app = $this->stringOption('app');
        $node = $this->stringOption('node');

        if ($node === null && $app === null) {
            try {
                $node = app(OrbitConfigStore::class)->defaultNode();
            } catch (OrbitConfigStoreException $exception) {
                return $this->renderFailure($exception->orbitCode, $exception->getMessage());
            }
        }

        $payload = $this->filledQuery([
            'app' => $app,
            'node' => $node,
        ]);

        if ($requireTarget && $payload === []) {
            return $this->renderFailure(
                'validation_failed',
                'A node or app target is required.',
                ['fields' => ['target']],
            );
        }

        return $payload;
    }
}
