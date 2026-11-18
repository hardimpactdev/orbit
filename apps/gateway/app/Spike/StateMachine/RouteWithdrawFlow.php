<?php

declare(strict_types=1);

namespace App\Spike\StateMachine;

use App\Models\Operation;
use App\Spike\Nats\Bus;
use Illuminate\Support\Carbon;

/**
 * SPIKE: taking back a Route that failed partway, from the restore half of the mutations the Gateway captured at admission
 * (`route_lifecycle.route_mutations`). The Gateway renders each Node's whole Caddyfile and DNS files, and the Route hold keeps
 * every other write off those Nodes, so taking it back is the forward primitives with the `before` artifacts, plus the
 * certificate scope folders gone. Then the create records its original failure.
 */
final class RouteWithdrawFlow
{
    public static function start(Operation $create, array $failure): Operation
    {
        $mutations = $create->input['route_lifecycle']['route_mutations'];
        $steps = [];
        foreach ($mutations['restore']['publications'] ?? [] as $p) {
            $n = (int) $p['node_id'];
            if (($p['serves_routes'] ?? false) && isset($p['before']['caddy'])) {
                $steps["wcaddy-$n"] = ['node' => $n, 'action' => 'caddy.config', 'after' => [], 'budget_s' => 120, 'resend_s' => 10];
            }
            if (isset($p['before']['dns'])) {
                $steps["wrecords-$n"] = ['node' => $n, 'action' => 'file.put', 'after' => [], 'budget_s' => 60];
                $steps["wcatalog-$n"] = ['node' => $n, 'action' => 'file.put', 'after' => [], 'budget_s' => 60];
                $steps["wrestart-$n"] = ['node' => $n, 'action' => 'systemd.restart', 'after' => ["wrecords-$n", "wcatalog-$n"], 'budget_s' => 60];
            }
        }
        foreach ($mutations['restore']['cleanup'] ?? [] as $i => $row) {
            if ($row['in_use'] ?? false) {
                continue;
            }
            $n = (int) $row['node_id'];
            // The folders go after the site that used them is gone.
            $after = isset($steps["wcaddy-$n"]) ? ["wcaddy-$n"] : [];
            $steps["wcert-$i"] = ['node' => $n, 'action' => 'file.remove_tree', 'after' => $after, 'budget_s' => 60];
            $steps["wacert-$i"] = ['node' => $n, 'action' => 'file.remove_tree', 'after' => $after, 'budget_s' => 60];
        }

        return Engine::start($steps, [], self::class, ['create_operation' => $create->id, 'failure' => $failure]);
    }

    public static function input(string $name, Operation $engine): array
    {
        [$kind, $key] = explode('-', $name, 2);
        $create = Operation::query()->findOrFail($engine->input['context']['create_operation']);
        $mutations = $create->input['route_lifecycle']['route_mutations'];
        $node = (int) $engine->input['steps'][$name]['node'];
        $publication = collect($mutations['restore']['publications'])->firstWhere('node_id', $node);
        $blob = static fn (string $content): array => Bus::putContent($node, $content);
        $dns = static fn () => $mutations['artifacts']['dns'][(int) $publication['before']['dns']];
        $cleanup = $mutations['restore']['cleanup'][(int) $key] ?? null;

        return match ($kind) {
            'wcaddy' => ['blob' => $blob($mutations['artifacts']['caddy'][(int) $publication['before']['caddy']]['content'])],
            'wrecords' => ['path' => '/etc/dnsmasq.d/orbit-records.conf', 'blob' => $blob($dns()['records']), 'owner' => 'root', 'group' => 'root', 'mode' => '0644'],
            'wcatalog' => ['path' => '/var/lib/orbit/private-dns/catalog.json', 'blob' => $blob($dns()['catalog']), 'owner' => 'root', 'group' => 'root', 'mode' => '0644'],
            'wrestart' => ['units' => ['dnsmasq', 'orbit-private-dns.service']],
            'wcert' => ['path' => '/etc/caddy/orbit-certificates/'.$cleanup['scope']],
            'wacert' => ['path' => '/home/'.$cleanup['user'].'/.orbit/certificates/'.$cleanup['scope']],
        };
    }

    public static function local(string $name, Operation $engine): string
    {
        throw new \LogicException('no Gateway steps');
    }

    public static function finished(Operation $engine, string $status): void
    {
        $create = Operation::query()->findOrFail($engine->input['context']['create_operation']);
        $at = fn (int $ms): string => Carbon::createFromTimestampMs($ms)->toIso8601ZuluString('millisecond');
        $failure = [...$engine->input['context']['failure'], 'route_withdrawn' => $status === 'succeeded', 'spike_withdraw_engine' => $engine->id];
        InstanceCreateFlow::fail($create, $failure, ['run_id' => 'spike-sm-'.$failure['spike_engine'],
            'started_at' => $at((int) $engine->state['started_ms']), 'finished_at' => $at($engine->state['finished_ms'] ?? Engine::nowMs())]);
    }
}
