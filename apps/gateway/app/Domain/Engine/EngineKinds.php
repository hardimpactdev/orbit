<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Domain\Firewall\FirewallEngineFlow;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Throwable;

/**
 * Which Operation kinds run on the state machine (ADR 0271 N4): a kind moves as a whole, and runs on the engine only when
 * the Gateway setting names it and every Node its steps touch has reported the primitives they need. Any other Operation
 * starts on Swamp.
 */
final readonly class EngineKinds
{
    /** @var array<string, class-string<EngineDefinition>> */
    public const array Definitions = [
        'firewall.allow' => FirewallEngineFlow::class,
        'firewall.deny' => FirewallEngineFlow::class,
        'firewall.remove' => FirewallEngineFlow::class,
    ];

    public function __construct(private MachineBus $bus) {}

    public function definition(string $kind): EngineDefinition
    {
        $class = self::Definitions[$kind] ?? throw new ResourceOperationException('engine.kind_unknown', "Kind [{$kind}] has no engine definition.", 422);

        return app($class);
    }

    /** Whether this Operation starts on the state machine. */
    public function routes(Operation $operation): bool
    {
        $enabled = config('orbit.engine.kinds');
        if (! isset(self::Definitions[$operation->kind]) || ! is_array($enabled) || ! in_array($operation->kind, $enabled, true)) {
            return false;
        }
        try {
            foreach ($this->definition($operation->kind)->steps($operation) as $step) {
                if ($step->node > 0 && ! in_array($step->action, $this->bus->nodeActions($step->node), true)) {
                    return false;
                }
            }
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return true;
    }
}
