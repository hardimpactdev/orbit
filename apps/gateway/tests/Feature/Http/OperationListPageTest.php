<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(LazilyRefreshDatabase::class);

/** docs/reference/operations.md#page-the-list: an MCP client must be able to take every page. */
function operation_page(string $query = ''): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => '10.44.0.2'])->getJson('/api/v1/operations'.$query);
}

beforeEach(function (): void {
    $gateway = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    // A large stored intent and result: what made the unpaged list 6.6 MB.
    $this->ids = collect(range(1, 25))->map(static fn (int $n): int => Operation::query()->create([
        'kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $n, 'node_id' => $gateway->id,
        'input' => ['plan' => str_repeat('x', 4096), 'commit' => str_repeat('a', 40)], 'result' => ['log' => str_repeat('y', 4096)],
        'status' => $n % 2 === 0 ? OperationStatus::Succeeded : OperationStatus::Running,
    ])->id)->all();
});

it('returns 20 rows by default, newest first, without input, result or steps', function (): void {
    $page = operation_page()->assertOk();

    expect($page->json('data'))->toHaveCount(20)
        ->and(array_column($page->json('data'), 'id'))->toBe(array_slice(array_reverse($this->ids), 0, 20))
        ->and($page->json('data.0'))->not->toHaveKeys(['input', 'result', 'steps'])
        ->and($page->json('data.0'))->toHaveKeys(['id', 'kind', 'status', 'error_code', 'finished_at', 'poll_after_seconds'])
        ->and($page->json('meta.next_cursor'))->toBe((string) $this->ids[5])
        ->and(strlen((string) $page->getContent()))->toBeLessThan(16_384);
});

it('pages with the cursor and the same filters until next_cursor is null', function (): void {
    $first = operation_page('?limit=3&status=running')->assertOk();
    $second = operation_page('?limit=3&status=running&cursor='.$first->json('meta.next_cursor'))->assertOk();
    $running = array_values(array_filter(array_reverse($this->ids), static fn (int $id): bool => Operation::query()->find($id)?->status === OperationStatus::Running));

    expect(array_column($first->json('data'), 'id'))->toBe(array_slice($running, 0, 3))
        ->and(array_column($second->json('data'), 'id'))->toBe(array_slice($running, 3, 3));

    $last = operation_page('?limit=100')->assertOk();
    expect($last->json('data'))->toHaveCount(25)->and($last->json('meta.next_cursor'))->toBeNull();
});

it('refuses a limit or cursor out of form', function (string $query, string $field): void {
    operation_page($query)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath("error.details.{$field}", fn (mixed $messages): bool => $messages !== null);
})->with([
    'limit 0' => ['?limit=0', 'limit'],
    'limit 101' => ['?limit=101', 'limit'],
    'cursor text' => ['?cursor=abc', 'cursor'],
    'cursor 0' => ['?cursor=0', 'cursor'],
]);

it('keeps input, result and steps on operation:show', function (): void {
    expect(test()->withServerVariables(['REMOTE_ADDR' => '10.44.0.2'])->getJson('/api/v1/operations/'.$this->ids[0])->assertOk()->json('data'))
        ->toHaveKeys(['input', 'result', 'steps']);
});
