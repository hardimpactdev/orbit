<?php

declare(strict_types=1);

use App\Actions\Fleet\AdmitFleetRolloutAction;
use App\Domain\Operations\NodeWorkAdmission;
use App\Models\Node;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'plan-node', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519',
        'ssh_host_key' => 'fixture-public-key']));
    // The SQLite query plan of every read of the operations table that the callback runs.
    $this->plans = function (Closure $callback): array {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'from "operations"')) {
                $queries[] = $query;
            }
        });
        try {
            $callback();
        } catch (Throwable) {
        }

        return array_map(static fn (QueryExecuted $query): string => implode("\n", array_map(static fn (object $row): string => $row->detail,
            DB::select('EXPLAIN QUERY PLAN '.$query->sql, $query->bindings))), $queries);
    };
    // No read scans the table, and none walks every row of a kind through the kind index alone.
    $this->bounded = static function (array $plans): void {
        expect($plans)->not->toBeEmpty();
        foreach ($plans as $plan) {
            expect($plan)->not->toContain('SCAN operations')->not->toMatch('/\(kind=\?\)$/m');
        }
    };
});

// The fleet pass runs these reads inside its write transaction, on a ledger of tens of thousands of finished Operations with large
// inputs: each must reach the few open rows, or the rows of one Node, through an index rather than read the whole table.
it('reads the open fleet rollouts through an index (QueryPlan)', function (): void {
    $plans = ($this->plans)(fn () => AdmitFleetRolloutAction::open());

    ($this->bounded)($plans);
});

it('reads the holders of a role converge through indexes (QueryPlan)', function (): void {
    $plans = ($this->plans)(fn () => app(NodeWorkAdmission::class)->check('node.converge', $this->node->id,
        ['_converge' => ['intent' => ['release_roles_only' => true, 'automatic' => true]]]));

    ($this->bounded)($plans);
});

it('reads the automatic converges that Instance work waits for through indexes (QueryPlan)', function (): void {
    $plans = ($this->plans)(fn () => app(NodeWorkAdmission::class)->check('instance.deploy', $this->node->id, []));

    ($this->bounded)($plans);
});
