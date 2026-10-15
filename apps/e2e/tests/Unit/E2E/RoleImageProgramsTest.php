<?php

declare(strict_types=1);

use App\E2E\RoleImagePrograms;
use App\E2E\Value\RoleImage;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

/** A repository whose Gateway artisan prints the programs it was asked for, and records its environment. */
function roleImageProgramsRoot(string $artisan): string
{
    $root = temporaryPath('orbit-role-programs-', 5);
    mkdir($root.'/apps/gateway', 0700, true);
    file_put_contents($root.'/apps/gateway/artisan', $artisan);

    return $root;
}

function renderingArtisan(): string
{
    return <<<'PHP'
        <?php
        $roles = array_values(array_filter(array_slice($argv, 2), static fn (string $argument): bool => ! str_starts_with($argument, '--')));
        file_put_contents(dirname(__DIR__, 2).'/rendered.json', json_encode(['argv' => array_slice($argv, 1), 'home' => getenv('HOME'), 'orbit_home' => getenv('ORBIT_HOME'), 'key' => file_get_contents(getenv('ORBIT_HOME').'/ssh/id_ed25519.pub'), 'app_key' => getenv('APP_KEY')]));
        $programs = [['name' => 'bootstrap', 'arguments' => ['sudo', '-n', '--', 'bash', '-seu', '--', ...$roles], 'input' => 'bootstrap']];
        foreach ($roles as $role) {
            $programs[] = ['name' => $role.'-prerequisites', 'arguments' => ['sudo', 'bash', '-seu'], 'input' => $role];
        }
        echo json_encode(['programs' => array_map(static fn (array $p): array => $p + ['fingerprint' => hash('sha256', json_encode(['arguments' => $p['arguments'], 'input' => $p['input']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))], $programs)]), "\n";
        PHP;
}

describe('RoleImagePrograms', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        putenv('APP_KEY=base64:leaked-from-the-harness');
    });

    afterEach(fn () => putenv('APP_KEY'));

    it('renders gateway and vpn programs for convergence without bootstrap or image preparation', function (): void {
        $programs = new RoleImagePrograms(roleImageProgramsRoot(renderingArtisan()))->for(RoleImage::Gateway);
        expect(array_column($programs, 'name'))->toBe(['gateway-prerequisites', 'vpn-prerequisites']);
        expect(array_unique(array_column($programs, 'user')))->toBe(['orbit']);
    });

    it('renders with a throwaway Orbit home and SSH key and none of the harness environment', function () {
        $root = roleImageProgramsRoot(renderingArtisan());
        new RoleImagePrograms($root)->for(RoleImage::AppProd);
        $rendered = json_decode((string) file_get_contents($root.'/rendered.json'), true, 8, JSON_THROW_ON_ERROR);

        expect($rendered['argv'])->toBe(['orbit:node-programs', 'app-prod', '--json', '--no-interaction'])
            ->and($rendered['orbit_home'])->toBe($rendered['home'])
            ->and($rendered['orbit_home'])->toStartWith(sys_get_temp_dir().'/orbit-e2e-programs-')
            ->and($rendered['key'])->toBe(RoleImagePrograms::PLACEHOLDER_KEY."\n")
            ->and($rendered['app_key'])->toBeFalse()
            ->and(is_dir($rendered['orbit_home']))->toBeFalse();
    });

    it('fails loudly when the Gateway cannot render or returns an invalid program', function (string $artisan, string $message) {
        $root = roleImageProgramsRoot($artisan);

        expect(fn () => new RoleImagePrograms($root)->for(RoleImage::AppDev))->toThrow(RuntimeException::class, $message);
    })->with([
        'command failure' => ["<?php fwrite(STDERR, 'Unknown role [app-dev].'); exit(1);", 'orbit:node-programs app-dev failed: Unknown role [app-dev].'],
        'invalid JSON' => ['<?php echo "not json";', 'returned invalid JSON'],
        'no programs' => ['<?php echo json_encode(["programs" => []]);', 'returned no programs'],
        'bad name' => ['<?php echo json_encode(["programs" => [["name" => "Bad Name", "arguments" => ["true"], "input" => null]]]);', 'returned an invalid program'],
        'a fingerprint in another format' => ['<?php echo json_encode(["programs" => [["name" => "ok", "arguments" => ["true"], "input" => null, "fingerprint" => hash("sha256", "other")]]]);', 'fingerprints [ok] differently from the harness'],
        'extra key' => ['<?php echo json_encode(["programs" => [["name" => "ok", "arguments" => ["true"], "input" => null, "protected" => true]]]);', 'returned an invalid program'],
    ]);
});
