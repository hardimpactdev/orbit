<?php

declare(strict_types=1);

use App\E2E\RoleImagePrograms;
use App\E2E\Value\RoleImage;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Process\Process;

/** A repository whose Gateway artisan prints the programs it was asked for, and records its environment. */
function roleImageProgramsRoot(string $artisan): string
{
    $root = temporaryPath('orbit-role-programs-', 5);
    mkdir($root.'/apps/gateway', 0700, true);
    mkdir($root.'/apps/e2e/resources/image', 0700, true);
    foreach (['role-image.sh', 'operator-tooling.sh'] as $script) {
        copy(dirname(__DIR__, 3).'/resources/image/'.$script, $root.'/apps/e2e/resources/image/'.$script);
    }
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

    it('runs the harness preparation as root, then the Gateway-rendered enrollment programs as orbit', function (RoleImage $image, array $names) {
        $root = roleImageProgramsRoot(renderingArtisan());
        $programs = new RoleImagePrograms($root)->for($image);

        expect(array_column($programs, 'name'))->toBe($names)
            ->and($programs[0])->toMatchArray(['user' => 'root', 'arguments' => ['bash', '-s', '--', 'base']])
            ->and($programs[0]['input'])->toBe(file_get_contents(dirname(__DIR__, 3).'/resources/image/role-image.sh'))
            ->and(array_unique(array_column(array_slice($programs, $image === RoleImage::AppDev || $image === RoleImage::AppProd ? 1 : 2), 'user')))->toBe(['orbit']);
    })->with([
        'gateway, without the bootstrap program' => [RoleImage::Gateway, ['orbit-e2e-base', 'orbit-e2e-gateway-prerequisites', 'gateway-prerequisites', 'vpn-prerequisites']],
        'app-dev' => [RoleImage::AppDev, ['orbit-e2e-base', 'bootstrap', 'app-dev-prerequisites']],
        'app-prod' => [RoleImage::AppProd, ['orbit-e2e-base', 'bootstrap', 'app-prod-prerequisites']],
        'roleless operator' => [RoleImage::Operator, ['orbit-e2e-base', 'orbit-e2e-operator-tooling', 'bootstrap']],
    ]);

    it('seals cloud images with DHCP restricted to the topology uplinks', function () {
        $root = temporaryPath('orbit-seal-network-', 4);
        mkdir($root.'/etc/systemd/network', 0o700, true);
        mkdir($root.'/etc/cloud', 0o700, true);
        mkdir($root.'/bin', 0o700, true);
        file_put_contents($root.'/etc/machine-id', "uninitialized\n");
        foreach (['cloud-init', 'sync', 'systemd-detect-virt'] as $command) {
            file_put_contents($root.'/bin/'.$command, "#!/bin/sh\nexit 0\n");
            chmod($root.'/bin/'.$command, 0o700);
        }
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/image/role-image.sh');
        file_put_contents($root.'/role-image.sh', str_replace(['/etc/', '/home/', '/root/'], [$root.'/etc/', $root.'/home/', $root.'/root/'], $source));
        $process = new Process(['bash', $root.'/role-image.sh', 'seal'], env: ['PATH' => $root.'/bin:'.getenv('PATH')]);

        expect($process->run())->toBe(0, $process->getErrorOutput());
        expect($process->getOutput())->toBe("{\"ok\":true}\n");
        expect(file_exists($root.'/etc/cloud/cloud-init.disabled'))->toBeTrue();
        expect(file_get_contents($root.'/etc/systemd/network/00-orbit-e2e.network'))
            ->toBe("[Match]\nType=ether\nName=en* eth*\n\n[Network]\nDHCP=ipv4\nLinkLocalAddressing=no\n");
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

    it('renders the same program list every time, so a fresh image is current', function () {
        $root = roleImageProgramsRoot(<<<'PHP'
            <?php
            $key = trim(file_get_contents(getenv('ORBIT_HOME').'/ssh/id_ed25519.pub'));
            echo json_encode(['programs' => array_map(static fn (array $p): array => $p + ['fingerprint' => hash('sha256', json_encode(['arguments' => $p['arguments'], 'input' => $p['input']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))], [['name' => 'bootstrap', 'arguments' => ['sudo', 'bash', '-seu', '--', $key], 'input' => $key]])]), "\n";
            PHP);
        $programs = new RoleImagePrograms($root);

        expect(RoleImagePrograms::listFingerprint($programs->for(RoleImage::AppProd)))
            ->toBe(RoleImagePrograms::listFingerprint($programs->for(RoleImage::AppProd)));
    });

    it('fingerprints a program as the Gateway image manifest does, by its arguments and input, and the list by names and fingerprints', function () {
        $program = ['name' => 'bootstrap', 'user' => 'orbit', 'arguments' => ['sudo', 'true'], 'input' => null];

        expect(RoleImagePrograms::fingerprint($program))
            ->toBe(hash('sha256', '{"arguments":["sudo","true"],"input":null}'))
            ->and(RoleImagePrograms::fingerprint([...$program, 'input' => 'x']))->not->toBe(RoleImagePrograms::fingerprint($program))
            ->and(RoleImagePrograms::listFingerprint([$program]))->not->toBe(RoleImagePrograms::listFingerprint([[...$program, 'name' => 'other']]));
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
