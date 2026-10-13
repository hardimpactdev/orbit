<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

use App\Domain\Tasks\AgentDriverException;

/**
 * Maps an Orbit model name to Pi's `provider/model` form. A task VM's Pi server has one provider, so every model runs
 * through it, and a provider the name carries is replaced.
 *
 * Claude models are refused: Anthropic permits subscription credentials only in its own
 * applications, including when a proxy relays them. They are unavailable for task agents.
 */
final readonly class PiModel
{
    public static function onlyProvider(string $model, string $provider): string
    {
        $slash = strpos($model, '/');
        $named = $slash === false ? null : substr($model, 0, $slash);
        $name = $slash === false ? $model : substr($model, $slash + 1);
        if ($named === 'anthropic' || str_starts_with($name, 'claude')) {
            throw new AgentDriverException('Claude models are unavailable for task agents.');
        }

        return $provider.'/'.$name;
    }
}
