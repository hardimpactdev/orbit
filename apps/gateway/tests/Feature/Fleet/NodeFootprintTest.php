<?php

declare(strict_types=1);

use App\Domain\Fleet\NodeFootprint;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Instance;
use App\Models\NodeFootprint as NodeFootprintRecord;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\Fleet\FakeFootprintArtifact;
use Tests\Support\Fleet\FleetFixtures;

describe('Node footprint', function (): void {
    it('applies every artifact the first time and records their digests', function (): void {
        $caddy = new FakeFootprintArtifact('caddy', 'c1');
        $agent = new FakeFootprintArtifact('agent', 'a1', changes: null);
        $proxy = new FakeFootprintArtifact('ai-router', 'p1', applies: false);
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);

        $result = new NodeFootprint(app(AgentDelivery::class), [$caddy, $agent, $proxy])->converge($node);

        expect($result->artifacts)->toBe(['agent' => 'applied', 'caddy' => 'applied'])
            ->and($result->changed())->toBeTrue()
            ->and($proxy->applied)->toBe([])
            ->and(NodeFootprintRecord::query()->sole()->artifacts)->toBe(new NodeFootprint(app(AgentDelivery::class), [$caddy, $agent])->expected($node)->artifacts)
            ->and(NodeFootprintRecord::query()->sole()->digest)->toBe(new NodeFootprint(app(AgentDelivery::class), [$caddy, $agent])->expected($node)->digest());
    });

    it('makes no call to the Node when every digest matches', function (): void {
        $caddy = new FakeFootprintArtifact('caddy', 'c1');
        $agent = new FakeFootprintArtifact('agent', 'a1');
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $footprint = new NodeFootprint(app(AgentDelivery::class), [$caddy, $agent]);
        $footprint->converge($node);
        $caddy->applied = $agent->applied = [];

        $result = $footprint->converge($node);

        expect($result->artifacts)->toBe(['agent' => 'unchanged', 'caddy' => 'unchanged'])
            ->and($result->changed())->toBeFalse()
            ->and($caddy->applied)->toBe([])
            ->and($agent->applied)->toBe([])
            ->and($footprint->drifted($node))->toBeFalse();
    });

    it('forgets a previously applied sandbox residue artifact without invoking any Node mutation', function (): void {
        $caddy = new FakeFootprintArtifact('caddy', 'c1');
        $agent = new FakeFootprintArtifact('agent', 'a1');
        $retired = new FakeFootprintArtifact('sandbox-residue', 's1');
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $old = new NodeFootprint(app(AgentDelivery::class), [$caddy, $agent, $retired]);
        $old->converge($node);
        $oldDigest = NodeFootprintRecord::query()->sole()->digest;
        $caddy->applied = $agent->applied = $retired->applied = [];
        // Any attempt to run the retired cleanup would fail, not silently pass this test.
        $retired->fails = true;
        $footprint = new NodeFootprint(app(AgentDelivery::class), [$caddy, $agent]);

        expect($footprint->drifted($node))->toBeTrue();

        $result = $footprint->converge($node);
        $stored = NodeFootprintRecord::query()->sole();

        expect($result->artifacts)->toBe(['agent' => 'unchanged', 'caddy' => 'unchanged'])
            ->and($result->changed())->toBeFalse()
            ->and($caddy->applied)->toBe([])
            ->and($agent->applied)->toBe([])
            ->and($retired->applied)->toBe([])
            ->and($stored->artifacts)->toBe(new NodeFootprint(app(AgentDelivery::class), [$caddy, $agent])->expected($node)->artifacts)
            ->and($stored->digest)->not->toBe($oldDigest)
            ->and($stored->digest)->toBe($footprint->expected($node)->digest())
            ->and($footprint->drifted($node))->toBeFalse();
    });

    it('re-applies only the artifact whose digest changed', function (): void {
        $caddy = new FakeFootprintArtifact('caddy', 'c1');
        $agent = new FakeFootprintArtifact('agent', 'a1');
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $footprint = new NodeFootprint(app(AgentDelivery::class), [$caddy, $agent]);
        $footprint->converge($node);
        $caddy->applied = $agent->applied = [];
        $caddy->digest = 'c2';

        expect($footprint->drifted($node))->toBeTrue();

        $result = $footprint->converge($node);

        expect($result->artifacts)->toBe(['agent' => 'unchanged', 'caddy' => 'applied'])
            ->and($caddy->applied)->toBe(['dev'])
            ->and($agent->applied)->toBe([]);
    });

    it('reports an artifact that found the live copy current as unchanged', function (): void {
        $caddy = new FakeFootprintArtifact('caddy', 'c1', changes: false);

        expect(new NodeFootprint(app(AgentDelivery::class), [$caddy])->converge(FleetFixtures::node('dev', [RoleName::AppDev]))->changed())->toBeFalse();
    });

    it('re-applies every artifact when forced', function (): void {
        $caddy = new FakeFootprintArtifact('caddy', 'c1');
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $footprint = new NodeFootprint(app(AgentDelivery::class), [$caddy]);
        $footprint->converge($node);

        $footprint->converge($node, force: true);

        expect($caddy->applied)->toBe(['dev', 'dev']);
    });

    it('names the failed artifact and applies it again next time', function (): void {
        $caddy = new FakeFootprintArtifact('caddy', 'c1');
        $agent = new FakeFootprintArtifact('agent', 'a1', fails: true);
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $footprint = new NodeFootprint(app(AgentDelivery::class), [$caddy, $agent]);

        expect(fn () => $footprint->converge($node))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('fake.artifact_failed')
                ->and($exception->details['artifact'])->toBe('agent');
        });

        $agent->fails = false;
        $agent->applied = [];
        $footprint->converge($node);

        expect($agent->applied)->toBe(['dev']);
    });

    it('never changes an Instance', function (): void {
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $before = Instance::query()->count();

        new NodeFootprint(app(AgentDelivery::class), [new FakeFootprintArtifact('caddy', 'c1')])->converge($node);

        expect(Instance::query()->count())->toBe($before);
    });
});

it('re-applies artifacts for a new native pin but ignores unrelated manifest changes', function (): void {
    $root = sys_get_temp_dir().'/orbit-footprint-delivery-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $manifest = ['commit' => str_repeat('a', 40), 'version' => '0.5.0', 'artifacts' => [
        'linux-x86_64' => ['sha256' => str_repeat('1', 64), 'path' => 'first'],
        'linux-aarch64' => ['sha256' => str_repeat('2', 64), 'path' => 'arm'],
    ]];
    $delivery = static function (array $contents) use ($root): AgentDelivery {
        file_put_contents($root.'/manifest.json', json_encode($contents, JSON_THROW_ON_ERROR));

        return new AgentDelivery($root.'/manifest.json', $root);
    };
    $caddy = new FakeFootprintArtifact('caddy', 'same-php-source');
    $package = new FakeFootprintArtifact('caddy-package', 'same-package-source');
    $node = FleetFixtures::node('dev', [RoleName::AppDev]);
    try {
        $first = new NodeFootprint($delivery($manifest), [$package, $caddy]);
        $first->converge($node);
        $original = $first->expected($node)->artifacts;
        $caddy->applied = $package->applied = [];
        $manifest['artifacts']['linux-x86_64']['sha256'] = str_repeat('3', 64);
        $updated = new NodeFootprint($delivery($manifest), [$package, $caddy]);
        expect($updated->drifted($node))->toBeTrue();
        $updated->converge($node);
        expect($caddy->applied)->toBe(['dev'])->and($package->applied)->toBe(['dev'])
            ->and($updated->expected($node)->artifacts['caddy'])->not->toBe($original['caddy'])
            ->and($updated->expected($node)->artifacts['caddy-package'])->not->toBe($original['caddy-package']);
        $caddy->applied = $package->applied = [];
        $manifest['artifacts']['linux-x86_64']['path'] = 'moved';
        $manifest['artifacts']['linux-aarch64']['sha256'] = str_repeat('4', 64);
        $manifest['commit'] = str_repeat('b', 40);
        $unchanged = new NodeFootprint($delivery($manifest), [$package, $caddy]);
        expect($unchanged->drifted($node))->toBeFalse();
        $unchanged->converge($node);
        expect($caddy->applied)->toBe([])->and($package->applied)->toBe([]);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});
