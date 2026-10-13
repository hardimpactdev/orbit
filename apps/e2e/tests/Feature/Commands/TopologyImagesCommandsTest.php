<?php

declare(strict_types=1);

use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\RoleImageBuilder;
use App\E2E\RoleImagePrograms;
use App\E2E\RoleImages;
use App\E2E\State\StatePaths;
use App\E2E\Value\OperationId;
use App\E2E\Value\RoleImage;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeIncus;

/** A committed checkout whose Gateway renders one program per role, with the real image scripts. */
function imagesCommandCheckout(): string
{
    $root = temporaryPath('orbit-images-command-', 5);
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
    app()->instance(RoleImagePrograms::class, new RoleImagePrograms($root));

    return $root;
}

/** Binds a builder on the fake Incus that answers a healthy build. */
function imagesCommandBuilder(FakeIncus $incus, string $root): void
{
    $incus->respond(static function (string $instance, array $argv): ?array {
        if (($argv[0] ?? null) === 'sh' && str_contains($argv[2] ?? '', 'ip -4 -o addr show')) {
            return ["2: eth0    inet 10.232.1.10/24 scope global eth0\n", '', 0];
        }
        if (($argv[0] ?? null) === 'sh' && str_contains($argv[2] ?? '', 'boot_id')) {
            return ["c1f2a3b4-0000-4000-8000-000000000001\n", '', 0];
        }

        return in_array($argv, [['bash', '-s'], ['bash', '-s', '--', 'seal']], true) ? ["{\"ok\":true}\n", '', 0] : null;
    });
    $host = new IncusHost(pool: 'orbit-e2e');
    app()->instance(RoleImageBuilder::class, new RoleImageBuilder(
        $host,
        new IncusNetworkLifecycle($host),
        new HostCapacity($host, 24),
        app(StatePaths::class),
        new OperationId(str_repeat('d', 32)),
        app(RoleImagePrograms::class),
        $root,
        'images:ubuntu/26.04/cloud',
        'images:ubuntu/26.04',
        settleIntervalMicroseconds: 0,
    ));
}

/** @return array<string, mixed> */
function imagesCommandJson(string $command, array $parameters = []): array
{
    Artisan::call($command, [...$parameters, '--json' => true]);

    return json_decode(trim(Artisan::output()), true, 16, JSON_THROW_ON_ERROR);
}

describe('topology image commands', function () {
    it('registers the build and status commands', function () {
        expect(array_keys(Artisan::all()))->toContain('topology-images:build', 'topology-images:status');
    });

    it('reports every role image row in JSON and text', function () {
        $incus = new FakeIncus()->install();
        imagesCommandCheckout();
        $current = RoleImagePrograms::listFingerprint(app(RoleImagePrograms::class)->for(RoleImage::AppDev));
        $appDev = $incus->addImage('orbit-e2e-app-dev', properties: [RoleImages::PROGRAMS_PROPERTY => $current, RoleImages::COMMIT_PROPERTY => str_repeat('2', 40)]);
        $operator = $incus->addImage('orbit-e2e-operator', 'container', [RoleImages::PROGRAMS_PROPERTY => $current]);

        $json = imagesCommandJson('topology-images:status');
        $exit = Artisan::call('topology-images:status');
        $text = Artisan::output();

        expect($json['images'])->toBe([
            ['role' => 'gateway', 'alias' => 'orbit-e2e-gateway', 'state' => 'missing', 'fingerprint' => null, 'created_at' => null, 'commit' => null, 'error' => null],
            ['role' => 'app-dev', 'alias' => 'orbit-e2e-app-dev', 'state' => 'current', 'fingerprint' => $appDev, 'created_at' => '2026-10-12T12:00:00Z', 'commit' => str_repeat('2', 40), 'error' => null],
            ['role' => 'app-prod', 'alias' => 'orbit-e2e-app-prod', 'state' => 'missing', 'fingerprint' => null, 'created_at' => null, 'commit' => null, 'error' => null],
            ['role' => 'operator', 'alias' => 'orbit-e2e-operator', 'state' => 'stale', 'fingerprint' => $operator, 'created_at' => '2026-10-12T12:00:00Z', 'commit' => null, 'error' => null],
        ])
            ->and($exit)->toBe(0)
            ->and(explode("\n", trim($text)))->toBe([
                'orbit-e2e-gateway    missing  - ',
                'orbit-e2e-app-dev    current  '.substr($appDev, 0, 12).' 2026-10-12T12:00:00Z',
                'orbit-e2e-app-prod   missing  - ',
                'orbit-e2e-operator   stale    '.substr($operator, 0, 12).' 2026-10-12T12:00:00Z',
            ]);
    });

    it('refuses an unknown role image before it touches Incus', function (array $roles) {
        $incus = new FakeIncus()->install();

        expect(Artisan::call('topology-images:build', ['--role' => $roles, '--json' => true]))->toBe(1)
            ->and(json_decode(trim(Artisan::output()), true, 8, JSON_THROW_ON_ERROR))
            ->toBe(['state' => 'failed', 'error' => 'A role image must be gateway, app-dev, app-prod, or operator.'])
            ->and($incus->calls)->toBe([]);
    })->with([
        'unknown role' => [['web']],
        'second Node of a role' => [['app-dev', 'app-prod-2']],
    ]);

    it('builds each named role image once, in the order named, and prints their fingerprints and timings', function () {
        $incus = new FakeIncus()->install();
        $root = imagesCommandCheckout();
        imagesCommandBuilder($incus, $root);

        $output = imagesCommandJson('topology-images:build', ['--role' => ['operator', 'app-dev', 'operator']]);

        expect(array_keys($output))->toBe(['state', 'images', 'timings'])
            ->and($output['state'])->toBe('built')
            ->and(array_keys($output['images']))->toBe(['operator', 'app-dev'])
            ->and($incus->images[$output['images']['app-dev']]['aliases'])->toContain('orbit-e2e-app-dev')
            ->and($incus->images[$output['images']['operator']]['aliases'])->toContain('orbit-e2e-operator')
            ->and($output['timings'])->toHaveKey('total')
            ->and($incus->instances)->toBe([]);
    });

    it('reports a failed build as JSON', function () {
        $incus = new FakeIncus()->install();
        $root = imagesCommandCheckout();
        imagesCommandBuilder($incus, $root);
        $incus->failWhen(static fn (array $call): ?string => $call[0] === 'publish' ? 'Error: disk full' : null);

        $exit = Artisan::call('topology-images:build', ['--role' => ['app-prod'], '--json' => true]);
        $output = json_decode(trim(Artisan::output()), true, 8, JSON_THROW_ON_ERROR);

        expect($exit)->toBe(1)
            ->and(array_keys($output))->toBe(['state', 'error'])
            ->and($output['state'])->toBe('failed')
            ->and($output['error'])->toContain('disk full')
            ->and($incus->images)->toBe([]);
    });
});
