<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessRuntime;
use App\Infrastructure\Gateway\GatewayHorizon;
use App\Models\Node;
use App\Models\Process;

/** The Node that hosts the serving release and the two Node Processes carried into its container. */
final readonly class GatewayReleaseHost
{
    public const string Project = 'orbit-releases';

    public const array Processes = ['horizon', 'schedule-work'];

    public static function inventory(Node $node): ?GatewayReleaseInventory
    {
        $layout = GatewayReleaseLayout::fromConfig();
        if (! $layout->usesContainers()) {
            return null;
        }

        return self::owns($node) ? $layout->observe() : null;
    }

    public static function owns(Node $node): bool
    {
        $host = app(GatewayServingHost::class)->nodeId() ?? app(NodeAccessAuthorizer::class)->activeGatewayId();

        return $host === $node->id;
    }

    /** The first move can carry only Process units that subsequent releases can manage by identity. */
    public static function initialWorkloadProblem(?string $receiptHorizon = null): ?string
    {
        $id = app(GatewayServingHost::class)->nodeId() ?? app(NodeAccessAuthorizer::class)->activeGatewayId();
        $node = $id === null ? null : Node::query()->find($id);
        if ($node === null) {
            return 'Identify the serving Gateway Node before moving its background workers.';
        }

        $checkout = GatewayReleaseLayout::fromConfig()->applicationPath;
        $processes = Process::query()->whereMorphedTo('owner', $node)
            ->where('runtime', ProcessRuntime::Systemd)->orderBy('id')->get();
        foreach ($processes as $process) {
            $command = $process->runtime_config['command'] ?? null;
            if (! is_array($command)) {
                continue;
            }
            $artisan = $command[1] ?? null;
            $action = $command[2] ?? null;
            if (! in_array($artisan, ['artisan', './artisan', $checkout.'/artisan'], true)) {
                continue;
            }
            $expected = match ($action) {
                'horizon' => 'horizon',
                'schedule:work' => $artisan === $checkout.'/artisan' || $process->working_directory === $checkout ? 'schedule-work' : null,
                default => null,
            };
            if ($expected !== null && $process->name !== $expected) {
                return "Gateway Process {$process->id} [{$process->name}] runs {$action}. Recreate it as [{$expected}] with the same configuration and desired state, then verify a host release before the move.";
            }
        }
        if ($receiptHorizon !== null) {
            $current = GatewayHorizon::releaseUnit($node, false);
            $allowed = $current === '' ? ['', 'orbit-gateway-horizon.service'] : [$current];
            if (! in_array($receiptHorizon, $allowed, true)) {
                return "The selected release recorded a different Horizon unit [{$receiptHorizon}]. Verify a host release with the current Horizon configuration and use that Operation for the move.";
            }
        }

        return null;
    }

    public static function retiredFpm(Node $node): bool
    {
        $inventory = self::inventory($node);

        return $inventory !== null && $inventory->hostRetired
            && ! $node->roles()->whereIn('role', [RoleName::AppDev, RoleName::AppProd])->exists();
    }

    /** @return array{project:string,name:string,release:string}|null */
    public static function target(Node $node): ?array
    {
        $inventory = self::inventory($node);
        if ($inventory === null || $inventory->current === null) {
            return null;
        }

        return ['project' => self::Project, 'name' => 'gateway-'.$inventory->current, 'release' => $inventory->commits[$inventory->current]];
    }

    public static function hasProcess(Process $process): bool
    {
        return $process->owner_type === Node::class && $process->runtime === ProcessRuntime::Systemd
            && in_array($process->name, self::Processes, true);
    }
}
