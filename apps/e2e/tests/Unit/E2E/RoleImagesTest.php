<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\RoleImagePrograms;
use App\E2E\RoleImages;
use App\E2E\Value\RoleImage;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;
use Tests\Support\FakeIncus;

/** A checkout whose Gateway renders one program per role, with the real image scripts; `$failing` makes it fail. */
function roleImagesCheckout(bool $failing = false): string
{
    $root = temporaryPath('orbit-role-images-', 5);
    mkdir($root.'/apps/gateway', 0700, true);
    mkdir($root.'/apps/e2e/resources/image', 0700, true);
    foreach (['role-image.sh', 'operator-tooling.sh'] as $script) {
        copy(dirname(__DIR__, 3).'/resources/image/'.$script, $root.'/apps/e2e/resources/image/'.$script);
    }
    file_put_contents($root.'/apps/gateway/artisan', $failing ? "<?php\nfwrite(STDERR, \"Gateway down\\n\");\nexit(1);\n" : <<<'PHP'
        <?php
        $roles = array_values(array_filter(array_slice($argv, 2), static fn (string $argument): bool => ! str_starts_with($argument, '--')));
        echo json_encode(['programs' => array_map(static fn (array $p): array => $p + ['fingerprint' => hash('sha256', json_encode(['arguments' => $p['arguments'], 'input' => $p['input']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))], [['name' => 'bootstrap', 'arguments' => ['sudo', 'true', ...$roles], 'input' => null]])]), "\n";
        PHP);

    return $root;
}

describe('RoleImages', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('reports each role image as current, stale, or missing against the programs the checkout renders', function () {
        $incus = new FakeIncus()->install();
        $programs = new RoleImagePrograms(roleImagesCheckout());
        $current = static fn (RoleImage $image): string => RoleImagePrograms::listFingerprint($programs->for($image));
        $gateway = $incus->addImage('orbit-e2e-gateway', properties: [
            RoleImages::PROGRAMS_PROPERTY => $current(RoleImage::Gateway),
            RoleImages::COMMIT_PROPERTY => str_repeat('1', 40),
        ]);
        $appDev = $incus->addImage('orbit-e2e-app-dev', properties: [RoleImages::PROGRAMS_PROPERTY => $current(RoleImage::Gateway)]);
        $operator = $incus->addImage('orbit-e2e-operator', 'container', [RoleImages::PROGRAMS_PROPERTY => $current(RoleImage::Operator)]);

        $rows = new RoleImages(new IncusHost(pool: 'orbit-e2e'), $programs)->status();

        expect($rows)->toBe([
            ['role' => 'gateway', 'alias' => 'orbit-e2e-gateway', 'state' => 'current', 'fingerprint' => $gateway, 'created_at' => '2026-10-12T12:00:00Z', 'commit' => str_repeat('1', 40), 'error' => null],
            ['role' => 'app-dev', 'alias' => 'orbit-e2e-app-dev', 'state' => 'stale', 'fingerprint' => $appDev, 'created_at' => '2026-10-12T12:00:00Z', 'commit' => null, 'error' => null],
            ['role' => 'app-prod', 'alias' => 'orbit-e2e-app-prod', 'state' => 'missing', 'fingerprint' => null, 'created_at' => null, 'commit' => null, 'error' => null],
            ['role' => 'operator', 'alias' => 'orbit-e2e-operator', 'state' => 'current', 'fingerprint' => $operator, 'created_at' => '2026-10-12T12:00:00Z', 'commit' => null, 'error' => null],
        ]);
    });

    it('reports an image without a programs fingerprint as stale', function () {
        $incus = new FakeIncus()->install();
        $incus->addImage('orbit-e2e-app-prod');

        $rows = new RoleImages(new IncusHost(pool: 'orbit-e2e'), new RoleImagePrograms(roleImagesCheckout()))->status();

        expect(array_column($rows, 'state', 'role'))->toBe(['gateway' => 'missing', 'app-dev' => 'missing', 'app-prod' => 'stale', 'operator' => 'missing']);
    });

    it('reports freshness as unknown with the reason when the checkout cannot render its programs', function () {
        $incus = new FakeIncus()->install();
        $incus->addImage('orbit-e2e-app-dev', properties: [RoleImages::PROGRAMS_PROPERTY => str_repeat('0', 64)]);

        $row = new RoleImages(new IncusHost(pool: 'orbit-e2e'), new RoleImagePrograms(roleImagesCheckout(failing: true)))->status()[1];

        expect($row['state'])->toBe('unknown')
            ->and($row['error'])->toBe('orbit:node-programs app-dev failed: Gateway down');
    });

    it('resolves each role image to the fingerprint its alias names', function () {
        $incus = new FakeIncus()->install();
        $gateway = $incus->addImage('orbit-e2e-gateway');
        $operator = $incus->addImage('orbit-e2e-operator', 'container');

        expect(new RoleImages(new IncusHost(pool: 'orbit-e2e'), new RoleImagePrograms(roleImagesCheckout()))->resolve([RoleImage::Gateway, RoleImage::Operator]))
            ->toBe(['gateway' => $gateway, 'operator' => $operator]);
    });

    it('names the build command for a missing role image', function (RoleImage $image) {
        new FakeIncus()->install()->addImage('orbit-e2e-gateway');

        expect(fn () => new RoleImages(new IncusHost(pool: 'orbit-e2e'), new RoleImagePrograms(roleImagesCheckout()))->resolve([RoleImage::Gateway, $image]))
            ->toThrow(RuntimeException::class, "The role image [orbit-e2e-{$image->value}] is missing; run bin/e2e-topology-images build --role={$image->value}.");
    })->with([RoleImage::AppDev, RoleImage::AppProd, RoleImage::Operator]);
});
