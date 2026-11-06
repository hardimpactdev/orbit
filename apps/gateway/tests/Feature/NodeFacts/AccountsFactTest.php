<?php

declare(strict_types=1);

use App\Actions\NodeFacts\RecordNodeFactAction;
use App\Domain\NodeFacts\NodeFactKind;
use App\Models\Node;
use App\Models\NodeFact;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(LazilyRefreshDatabase::class);

function accounts_fact(array ...$accounts): array
{
    return ['accounts' => array_map(static fn (array $account): array => $account + [
        'user' => 'orbit', 'uid' => 1000, 'group' => 'orbit', 'home' => '/home/orbit', 'shell' => '/bin/bash',
    ], $accounts ?: [[]])];
}

it('accepts the accounts the agent reports', function (): void {
    $fact = accounts_fact([], ['user' => 'orbit-app-1', 'uid' => 999, 'group' => 'orbit-app-1', 'home' => '/home/orbit-app-1', 'shell' => '/usr/sbin/nologin'], ['user' => 'host$']);

    expect(Validator::make($fact, NodeFactKind::Accounts->rules())->passes())->toBeTrue()
        ->and(Validator::make(['accounts' => []], NodeFactKind::Accounts->rules())->passes())->toBeTrue();
});

it('refuses a report with an unsafe name, a relative path or too many accounts', function (array $fact): void {
    expect(Validator::make($fact, NodeFactKind::Accounts->rules())->fails())->toBeTrue();
})->with([
    'upper case name' => [accounts_fact(['user' => 'Orbit'])],
    'name with a space' => [accounts_fact(['user' => 'or bit'])],
    'group with a newline' => [accounts_fact(['group' => "orbit\nroot"])],
    'relative home' => [accounts_fact(['home' => 'home/orbit'])],
    'relative shell' => [accounts_fact(['shell' => 'bash'])],
    'negative uid' => [accounts_fact(['uid' => -1])],
    'missing field' => [['accounts' => [['user' => 'orbit']]]],
    'too many accounts' => [['accounts' => array_fill(0, 513, accounts_fact()['accounts'][0])]],
]);

it('stores the accounts of a Node once and rewrites them only when they change', function (): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'apps', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.5',
        'public_ssh_host' => '10.44.0.5', 'user' => 'orbit',
    ]));
    $record = app(RecordNodeFactAction::class);
    $at = CarbonImmutable::parse('2026-11-06T10:00:00Z');

    expect($record->execute($node, NodeFactKind::Accounts, '', $at, accounts_fact()))->toBeTrue()
        ->and($record->execute($node, NodeFactKind::Accounts, '', $at, accounts_fact()))->toBeFalse()
        ->and($record->execute($node, NodeFactKind::Accounts, '', $at, accounts_fact([], ['user' => 'alice', 'uid' => 1001, 'group' => 'alice', 'home' => '/home/alice'])))->toBeTrue();

    $fact = NodeFact::query()->where('node_id', $node->id)->where('kind', 'accounts')->sole();
    expect($fact->data['accounts'])->toHaveCount(2)->and($fact->data['accounts'][1]['home'])->toBe('/home/alice');
});
