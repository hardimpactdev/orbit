<?php

declare(strict_types=1);

use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\RoleImageBuilder;
use App\E2E\RoleImagePrograms;
use App\E2E\RoleImages;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\OperationId;
use App\E2E\Value\RoleImage;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;
use Tests\Support\FakeIncus;

/** A git checkout whose Gateway renders one program per role, with the real image scripts. */
function roleImageBuilderRoot(): string
{
    $root = temporaryPath('orbit-role-builder-', 5);
    mkdir($root.'/apps/gateway', 0700, true);
    mkdir($root.'/apps/e2e/resources/image', 0700, true);
    mkdir($root.'/apps/gateway/resources/task-vms', 0700, true);
    copy(dirname(__DIR__, 4).'/gateway/resources/task-vms/base-image-clean.sh', $root.'/apps/gateway/resources/task-vms/base-image-clean.sh');
    foreach (['role-image.sh', 'operator-tooling.sh'] as $script) {
        copy(dirname(__DIR__, 3).'/resources/image/'.$script, $root.'/apps/e2e/resources/image/'.$script);
    }
    file_put_contents($root.'/apps/gateway/artisan', <<<'PHP'
        <?php
        $roles = array_values(array_filter(array_slice($argv, 2), static fn (string $argument): bool => ! str_starts_with($argument, '--')));
        echo json_encode(['programs' => array_map(static fn (array $p): array => $p + ['fingerprint' => hash('sha256', json_encode(['arguments' => $p['arguments'], 'input' => $p['input']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))], [['name' => 'bootstrap', 'arguments' => ['sudo', 'true', ...$roles], 'input' => null]])]), "\n";
        PHP);
    exec('git -C '.escapeshellarg($root).' init -q && git -C '.escapeshellarg($root).' add . && git -C '.escapeshellarg($root).' -c user.name=Test -c user.email=test@example.test commit -q -m fixture', result_code: $status);
    expect($status)->toBe(0);

    return $root;
}

/** @return array{builder:RoleImageBuilder, incus:FakeIncus, paths:StatePaths, root:string} */
function roleImageBuilderFixture(?Closure $responder = null): array
{
    $incus = new FakeIncus()->install();
    $boot = 'c1f2a3b4-0000-4000-8000-000000000001';
    $incus->respond(function (string $instance, array $argv, ?string $stdin) use ($responder, $boot): ?array {
        $answer = $responder === null ? null : $responder($instance, $argv, $stdin);
        if ($answer !== null) {
            return $answer;
        }
        if (($argv[0] ?? null) === 'sh' && str_contains($argv[2] ?? '', 'ip -4 -o addr show')) {
            return ["2: eth0    inet 10.232.1.10/24 scope global eth0\n", '', 0];
        }
        if (($argv[0] ?? null) === 'sh' && str_contains($argv[2] ?? '', 'boot_id')) {
            return [$boot."\n", '', 0];
        }
        if (in_array($argv, [['bash', '-s'], ['bash', '-s', '--', 'seal']], true)) {
            return ["{\"ok\":true}\n", '', 0];
        }

        return null;
    });
    $root = roleImageBuilderRoot();
    $paths = StatePaths::forPrimary($root);
    $host = new IncusHost(pool: 'orbit-e2e');
    $builder = new RoleImageBuilder(
        $host,
        new IncusNetworkLifecycle($host),
        new HostCapacity($host, 24),
        $paths,
        new OperationId(str_repeat('d', 32)),
        new RoleImagePrograms($root),
        $root,
        'images:ubuntu/26.04/cloud',
        'images:ubuntu/26.04',
        settleIntervalMicroseconds: 0,
    );

    return ['builder' => $builder, 'incus' => $incus, 'paths' => $paths, 'root' => $root];
}

describe('RoleImageBuilder', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('builds identity-free role images from the stock images and moves each alias to its new image', function () {
        ['builder' => $builder, 'incus' => $incus, 'root' => $root] = roleImageBuilderFixture();
        $previous = $incus->addImage('orbit-e2e-app-dev');
        $incus->images[$previous]['aliases'][] = 'orbit-e2e-app-dev-20261001000000';

        $built = $builder->build(RoleImage::cases());

        expect(array_keys($built))->toBe(['gateway', 'app-dev', 'app-prod', 'operator']);
        foreach (RoleImage::cases() as $image) {
            $published = $incus->images[$built[$image->value]];
            expect($published['aliases'])->toContain($image->alias())
                ->and($published['type'])->toBe($image->instanceType())
                ->and($published['properties'][RoleImages::PROGRAMS_PROPERTY])->toMatch('/\A[0-9a-f]{64}\z/')
                ->and($published['properties'][RoleImages::COMMIT_PROPERTY])->toMatch('/\A[0-9a-f]{40}\z/');
        }
        $inits = $incus->callsOf('init');
        $manifests = array_values(array_filter($incus->execs, static fn (array $exec): bool => in_array(RoleImage::MANIFEST_PATH, $exec['argv'], true)));
        $manifest = json_decode((string) $manifests[1]['stdin'], true, 8, JSON_THROW_ON_ERROR);

        expect(array_column($inits, 1))->toBe(['images:ubuntu/26.04/cloud', 'images:ubuntu/26.04/cloud', 'images:ubuntu/26.04/cloud', 'images:ubuntu/26.04'])
            ->and(implode(' ', $inits[0]))->toContain("cloud-init.user-data=#cloud-config\nusers: []\n")
            ->and(implode(' ', $inits[3]))->not->toContain('cloud-init.user-data')
            ->and($manifest)->toMatchArray(['schema' => 1, 'role' => 'app-dev'])
            ->and(array_keys($manifest['programs']))->toBe(['orbit-e2e-base', 'bootstrap'])
            ->and($incus->instances)->toBe([])
            ->and($incus->networks)->toBe([])
            ->and(array_column($incus->firewall, 'operation'))->toBe(['ensure', 'remove'])
            ->and($incus->images)->not->toHaveKey($previous);
    });

    it('runs program N of every role before program N+1, then the Gateway identity removal, then the seal', function () {
        ['builder' => $builder, 'incus' => $incus] = roleImageBuilderFixture();
        $builder->build([RoleImage::Gateway, RoleImage::Operator]);

        $steps = [];
        foreach ($incus->execs as $exec) {
            $role = str_ends_with($exec['instance'], 'gateway') ? 'gateway' : 'operator';
            $argv = $exec['argv'];
            if ($argv === ['bash', '-s']) {
                $steps[] = $role.':gateway-clean';
            } elseif (array_slice($argv, 0, 3) === ['bash', '-s', '--']) {
                $steps[] = $role.':'.($argv[3] ?? 'tooling');
            } elseif (in_array('sudo', $argv, true)) {
                $steps[] = $role.':'.implode(' ', array_slice($argv, array_search('sudo', $argv, true)));
            }
        }

        expect($steps)->toBe([
            'gateway:base', 'operator:base',
            'gateway:gateway-prerequisites', 'operator:tooling',
            'operator:sudo true',
            'gateway:gateway-clean', 'operator:gateway-clean',
            'gateway:seal', 'operator:seal',
        ]);
    });

    it('removes its builders and network and keeps the current images when a program fails', function () {
        ['builder' => $builder, 'incus' => $incus] = roleImageBuilderFixture(
            fn (string $instance, array $argv): ?array => str_ends_with($instance, 'app-prod') && in_array('sudo', $argv, true)
                ? ['', "E: Unable to locate package\n", 100]
                : null,
        );
        $current = $incus->addImage('orbit-e2e-app-prod');

        expect(fn () => $builder->build([RoleImage::AppDev, RoleImage::AppProd]))
            ->toThrow(RuntimeException::class, 'program app-prod.bootstrap exited 100')
            ->and($incus->instances)->toBe([])
            ->and($incus->networks)->toBe([])
            ->and(array_keys($incus->images))->toBe([$current])
            ->and($incus->callsOf('publish'))->toBe([]);
    });

    it('deletes a published image that never became the role image when a later publish fails', function () {
        ['builder' => $builder, 'incus' => $incus] = roleImageBuilderFixture();
        $incus->failWhen(fn (array $call): ?string => $call[0] === 'publish' && str_ends_with($call[1], 'app-prod') ? 'Error: disk full' : null);

        expect(fn () => $builder->build([RoleImage::AppDev, RoleImage::AppProd]))
            ->toThrow(RuntimeException::class, 'disk full')
            ->and($incus->images)->toBe([])
            ->and($incus->instances)->toBe([]);
    });

    it('refuses to start while another build holds the lock', function () {
        ['builder' => $builder, 'incus' => $incus, 'paths' => $paths] = roleImageBuilderFixture();
        $lock = new OperationLock($paths);
        expect($lock->acquire(RoleImageBuilder::LOCK, new OperationId(str_repeat('e', 32))))->toBeTrue();

        try {
            expect(fn () => $builder->build([RoleImage::AppDev]))
                ->toThrow(RuntimeException::class, 'Another role image build is running.')
                ->and($incus->calls)->toBe([]);
        } finally {
            $lock->release();
        }
    });
});
