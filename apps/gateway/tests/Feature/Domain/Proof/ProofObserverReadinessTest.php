<?php

declare(strict_types=1);

use App\Actions\NodeFacts\RecordNodeFactAction;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Proof\ProofObserverReadiness;
use App\Infrastructure\AgentView\AgentReportedVersions;
use App\Models\Node;
use App\Models\NodeFact;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->versions = new AgentReportedVersions(new Repository(new ArrayStore));
    $this->readiness = new ProofObserverReadiness($this->versions);
    $this->expected = '0.14.0+'.str_repeat('b', 16);
    $this->nodes = collect(['gateway', 'app-dev', 'app-prod'])->map(fn (string $name): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'public_ssh_host' => '192.0.2.1', 'name' => $name, 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit',
    ])));
    $this->report = function (Node $node, string $version): void {
        $this->versions->record($node->id, $version);
        app(RecordNodeFactAction::class)->execute($node, NodeFactKind::Host, '', CarbonImmutable::now(), ['os' => 'linux', 'arch' => 'x86_64', 'filesystems' => []]);
    };
});

it('rejects old reports and waits until every new process reports the pinned version and a host fact', function (): void {
    foreach ($this->nodes as $node) {
        ($this->report)($node, $this->expected);
    }
    expect($this->readiness->ready($this->expected))->toBeTrue();
    $this->readiness->reset();
    expect(NodeFact::query()->count())->toBe(0)->and($this->readiness->ready($this->expected))->toBeFalse();
    ($this->report)($this->nodes[0], $this->expected);
    ($this->report)($this->nodes[1], $this->expected);
    ($this->report)($this->nodes[2], '0.13.0+'.str_repeat('a', 16));
    expect($this->readiness->ready($this->expected))->toBeFalse();
    $this->versions->record($this->nodes[2]->id, $this->expected);
    NodeFact::query()->where('node_id', $this->nodes[2]->id)->delete();
    expect($this->readiness->ready($this->expected))->toBeFalse();
    ($this->report)($this->nodes[2], $this->expected);
    expect($this->readiness->ready($this->expected))->toBeTrue();
});

it('does not accept an on-disk replacement or an absent nested Node as a report', function (): void {
    expect($this->readiness->ready($this->expected))->toBeFalse();
    $this->nodes[2]->delete();
    expect($this->readiness->ready($this->expected))->toBeFalse();
});
