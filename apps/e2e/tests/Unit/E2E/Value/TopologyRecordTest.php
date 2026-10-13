<?php

declare(strict_types=1);

use App\E2E\Value\TopologyRecord;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\MountPath;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyConstructionInputs;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\VerificationReport;
use Illuminate\Filesystem\Filesystem;

/** @param array<string, array{device:string,source:string,path:string}>|null $mounts */
function mountedTopologyFixture(bool $mounted = true, ?array $mounts = null, ?TopologyRecipe $recipe = null): TopologyRecord
{
    $construction = topologyConstructionFixture('TST-321', 'a', $recipe);
    $mount = ['device' => 'orbit-source', 'source' => '/srv/wt', 'path' => '/home/orbit/orbit'];
    $mounts ??= $mounted ? array_fill_keys($construction->target->recipe->checkoutNodeKeys(), $mount) : [];

    return new TopologyRecord(
        $construction,
        new SourceState(
            str_repeat('b', 40),
            str_repeat('b', 40),
            mounted: $mounted,
            mountTokenHash: $mounted ? str_repeat('e', 64) : null,
        ),
        new VerificationReport(true, ['fixture' => verificationProbeFixture()]),
        $mounts,
    );
}

describe('topology record', function () {
    it('round-trips the declared Nodes, role images, mounts, and mounted source state', function (array $workloads) {
        $topology = mountedTopologyFixture(recipe: TopologyRecipe::compose($workloads));
        $value = $topology->toArray();

        expect($value)->not->toHaveKey('purpose')
            ->and($value['nodes'])->toBe($topology->target->recipe->nodeKeys())
            ->and(array_keys($value['construction']['images']))->toBe(array_map(
                static fn ($image): string => $image->value,
                $topology->target->recipe->images(),
            ))
            ->and(array_keys($value['mounts']))->toBe($topology->target->recipe->checkoutNodeKeys())
            ->and(TopologyRecord::fromArray($value)->toArray())->toBe($value)
            ->and(mountedTopologyFixture(false)->toArray()['mounts'])->toBe([]);
    })->with([
        'gateway only' => [[]],
        'default' => [['app-dev', 'app-prod']],
        'full' => [['app-dev', 'app-prod', 'app-prod-2']],
    ]);

    it('rejects each redundant wire identity that conflicts with construction inputs', function (string $field, mixed $replacement): void {
        $value = mountedTopologyFixture(false)->toArray();
        if ($field === 'instances') {
            $value['instances']['gateway'] = $replacement;
        } else {
            $value[$field] = $replacement;
        }

        expect(fn () => TopologyRecord::fromArray($value))
            ->toThrow(InvalidArgumentException::class, 'identity does not match its construction inputs');
    })->with([
        'issue' => ['issue', 'TST-322'],
        'attempt' => ['attempt_id', str_repeat('b', 32)],
        'nodes' => ['nodes', ['gateway', 'operator']],
        'network' => ['network', 'oe-forged'],
        'instances' => ['instances', 'orbit-e2e-forged'],
    ]);

    it('requires one identical mount per checkout Node exactly when the source is mounted', function () {
        $mount = static fn (string $source = '/srv/wt', string $device = 'orbit-source'): array => ['device' => $device, 'source' => $source, 'path' => '/home/orbit/orbit'];

        expect(fn () => mountedTopologyFixture(true, []))
            ->toThrow(InvalidArgumentException::class, 'do not match the source state')
            ->and(fn () => mountedTopologyFixture(false, ['gateway' => $mount(), 'app-dev' => $mount(), 'operator' => $mount()]))
            ->toThrow(InvalidArgumentException::class, 'do not match the source state')
            ->and(fn () => mountedTopologyFixture(true, ['gateway' => $mount(), 'app-prod' => $mount()]))
            ->toThrow(InvalidArgumentException::class, 'do not match the source state')
            ->and(fn () => mountedTopologyFixture(true, ['gateway' => $mount(), 'app-dev' => $mount('/srv/other'), 'operator' => $mount('/srv/other')]))
            ->toThrow(InvalidArgumentException::class, 'share one source')
            ->and(fn () => mountedTopologyFixture(true, ['gateway' => $mount(device: 'other'), 'app-dev' => $mount(device: 'other'), 'operator' => $mount(device: 'other')]))
            ->toThrow(InvalidArgumentException::class, 'mount is invalid')
            ->and(fn () => mountedTopologyFixture(true, ['gateway' => $mount('relative'), 'app-dev' => $mount('relative'), 'operator' => $mount('relative')]))
            ->toThrow(InvalidArgumentException::class, 'mount is invalid');
    });

    it('names the harness that created a record of another schema', function (mixed $schema) {
        $value = mountedTopologyFixture()->toArray();
        $value['schema'] = $schema;
        $version = json_encode($schema, JSON_THROW_ON_ERROR);

        expect(TopologyRecord::SCHEMA)->toBe(5)
            ->and(fn () => TopologyRecord::fromArray($value))
            ->toThrow(InvalidArgumentException::class, "schema {$version} is not supported; release it with the harness that created it");
    })->with(['schema 4' => [4], 'string schema' => ['5']]);

    it('refuses a construction record whose images or Nodes do not match its declaration', function () {
        $value = mountedTopologyFixture(false)->toArray()['construction'];
        $missingImage = $value;
        unset($missingImage['images']['operator']);
        $badFingerprint = $value;
        $badFingerprint['images']['gateway'] = 'short';
        $foreignNode = $value;
        $foreignNode['nodes']['app-dev']['incus_address'] = '10.232.9.11';

        expect(TopologyConstructionInputs::fromArray($value)->toArray())->toBe($value)
            ->and(fn () => TopologyConstructionInputs::fromArray($missingImage))
            ->toThrow(InvalidArgumentException::class, 'role images do not match its Nodes')
            ->and(fn () => TopologyConstructionInputs::fromArray($badFingerprint))
            ->toThrow(InvalidArgumentException::class, 'fingerprint is invalid')
            ->and(fn () => TopologyConstructionInputs::fromArray($foreignNode))
            ->toThrow(InvalidArgumentException::class, 'Topology construction Node [app-dev] is invalid.');
    });
});

describe('mount paths', function () {
    it('accepts only absolute separator-free paths', function (string $path, bool $safe) {
        expect(MountPath::isSafe($path))->toBe($safe);
    })->with([
        'absolute' => ['/srv/wt', true],
        'relative' => ['srv/wt', false],
        'comma' => ['/srv/a,b', false],
        'equals' => ['/srv/a=b', false],
        'newline' => ["/srv/a\nb", false],
        'nul' => ["/srv/a\0b", false],
        'trailing slash' => ['/srv/wt/', false],
    ]);

    it('requires a mountable source to be a real directory', function () {
        $root = temporaryPath('orbit-mount-path-', 4);
        mkdir($root.'/tree', 0o700, true);
        symlink($root.'/tree', $root.'/link');
        file_put_contents($root.'/file', '');

        try {
            expect(MountPath::isMountableDirectory($root.'/tree'))
                ->toBeTrue()
                ->and(MountPath::isMountableDirectory($root.'/link'))
                ->toBeFalse()
                ->and(MountPath::isMountableDirectory($root.'/file'))
                ->toBeFalse()
                ->and(MountPath::isMountableDirectory($root.'/missing'))
                ->toBeFalse()
                ->and(MountPath::isMountableDirectory($root.'/tree/'))
                ->toBeFalse();
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });
});

describe('source state', function () {
    it('serializes and validates the mounted flag', function () {
        $state = new SourceState(
            str_repeat('b', 40),
            str_repeat('b', 40),
            mounted: true,
            mountTokenHash: str_repeat('e', 64),
        );
        $value = $state->toArray();

        expect($value['mounted'])
            ->toBeTrue()
            ->and($value['mount_token_sha256'])
            ->toBe(str_repeat('e', 64))
            ->and(SourceState::fromArray($value)->mounted)
            ->toBeTrue()
            ->and(SourceState::fromArray($value)->mountTokenHash)
            ->toBe(str_repeat('e', 64))
            ->and(new SourceState(str_repeat('b', 40), str_repeat('b', 40))->mounted)
            ->toBeFalse();

        $value['mounted'] = 'yes';
        expect(fn () => SourceState::fromArray($value))
            ->toThrow(InvalidArgumentException::class, 'schema is invalid');
        unset($value['mounted']);
        expect(fn () => SourceState::fromArray($value))
            ->toThrow(InvalidArgumentException::class, 'schema is invalid');
    });

    it('binds the mount token hash to the mounted flag', function () {
        $value = new SourceState(
            str_repeat('b', 40),
            str_repeat('b', 40),
            mounted: true,
            mountTokenHash: str_repeat('e', 64),
        )->toArray();
        unset($value['mount_token_sha256']);

        expect(fn () => new SourceState(str_repeat('b', 40), str_repeat('b', 40), mounted: true))
            ->toThrow(InvalidArgumentException::class, 'exactly one mount token hash')
            ->and(fn () => new SourceState(str_repeat('b', 40), str_repeat('b', 40), mountTokenHash: str_repeat('e', 64)))
            ->toThrow(InvalidArgumentException::class, 'exactly one mount token hash')
            ->and(fn () => new SourceState(
                str_repeat('b', 40),
                str_repeat('b', 40),
                mounted: true,
                mountTokenHash: 'short',
            ))
            ->toThrow(InvalidArgumentException::class, 'mount token hash is invalid')
            ->and(fn () => SourceState::fromArray($value))
            ->toThrow(InvalidArgumentException::class, 'schema is invalid');
    });
});

describe('incus instance disks', function () {
    it('keeps validated non-root disk devices', function () {
        $instance = new IncusInstance('local', 'default', 'vm', 'default', disks: [
            'orbit-source' => ['source' => '/srv/wt', 'path' => '/home/orbit/orbit'],
        ]);

        expect($instance->disk('orbit-source'))
            ->toBe(['source' => '/srv/wt', 'path' => '/home/orbit/orbit'])
            ->and(fn () => new IncusInstance('local', 'default', 'vm', 'default', disks: [
                'root' => ['source' => '/srv/wt', 'path' => '/home/orbit/orbit'],
            ]))
            ->toThrow(InvalidArgumentException::class, 'disk device')
            ->and(fn () => new IncusInstance('local', 'default', 'vm', 'default', disks: [
                'orbit-source' => ['source' => 'relative', 'path' => '/home/orbit/orbit'],
            ]))
            ->toThrow(InvalidArgumentException::class, 'disk device');
    });
});
