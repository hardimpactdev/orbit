<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Domain\Engine\MachineBus;
use App\Domain\Executor\SignedExecutorOrder;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** A machine bus whose Node executors report `$clock` for their clock. */
function clock_bus(?bool $clock): MachineBus
{
    return new class($clock) implements MachineBus
    {
        public function __construct(private ?bool $clock) {}

        public function publishOrder(int $node, SignedExecutorOrder $order): void {}

        public function publishStart(int $operation): void {}

        public function scheduleDeadline(int $operation, string $step, int $sends, int $atMs): void {}

        public function nudge(int $operation, string $id): void {}

        public function putContent(int $node, string $content): array
        {
            return ['sha256' => hash('sha256', $content)];
        }

        public function nodeActions(int $node): array
        {
            return [];
        }

        public function nodeClock(int $node): ?bool
        {
            return $this->clock;
        }
    };
}

/** @return list<string> the codes of the Node family's findings while the Node's executor reports `$clock` */
function clock_doctor(?bool $clock): array
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'vpn', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.1',
        'wireguard_ip' => '10.44.0.1', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fingerprint',
    ]));
    app()->instance(MachineBus::class, clock_bus($clock));
    $report = app(FactDoctor::class)->report([$node], [DoctorFamily::Node])->toArray();

    return array_column($report['nodes'][0]['families'][0]['issues'], 'code');
}

it('warns about a Node whose executor reports a clock that follows no time source, because it would refuse signed orders', function (): void {
    expect(clock_doctor(false))->toContain('node.clock_unsynchronized');
});

it('stays quiet for a synchronized clock and for a Node without a fresh executor report', function (?bool $clock): void {
    expect(clock_doctor($clock))->not->toContain('node.clock_unsynchronized')
        ->not->toContain('node.inspection_failed');
})->with(['synchronized' => true, 'no report' => null]);
