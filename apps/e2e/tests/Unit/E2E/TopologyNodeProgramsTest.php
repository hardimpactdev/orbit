<?php

declare(strict_types=1);

use App\E2E\TopologyNodePrograms;
use App\E2E\Value\TopologyRecipe;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

/** A repository whose Gateway artisan prints the programs it was asked for, and records its environment. */
function topologyNodeProgramsRoot(string $artisan): string
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
        echo json_encode(['agent' => ['path' => dirname(__DIR__, 2).'/apps/agent/delivery/orbit-agent', 'sha256' => str_repeat('a', 64)], 'programs' => array_map(static fn (array $p): array => $p + ['fingerprint' => hash('sha256', json_encode(['arguments' => $p['arguments'], 'input' => $p['input']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))], $programs)]), "\n";
        PHP;
}

describe('TopologyNodePrograms', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        putenv('APP_KEY=base64:leaked-from-the-harness');
    });

    afterEach(fn () => putenv('APP_KEY'));

    it('renders the Gateway purpose roles for convergence without the SSH bootstrap program', function (): void {
        $root = topologyNodeProgramsRoot(renderingArtisan());
        $rendered = new TopologyNodePrograms($root)->for(TopologyRecipe::fromOption(null)->node('gateway'), 'x86_64');
        $call = json_decode((string) file_get_contents($root.'/rendered.json'), true, 8, JSON_THROW_ON_ERROR);

        expect($call['argv'])->toBe(['orbit:node-programs', 'gateway', 'vpn', '--architecture=x86_64', '--json', '--no-interaction'])
            ->and(array_column($rendered['programs'], 'name'))->toBe(['gateway-prerequisites', 'vpn-prerequisites'])
            ->and(array_unique(array_column($rendered['programs'], 'user')))->toBe(['orbit'])
            ->and($rendered['agent'])->toBe(['path' => '/home/orbit/orbit/apps/agent/delivery/orbit-agent', 'sha256' => str_repeat('a', 64)]);
    });

    it('keeps the SSH bootstrap program for a workload Node', function (): void {
        $programs = new TopologyNodePrograms(topologyNodeProgramsRoot(renderingArtisan()))->for(TopologyRecipe::fromOption('app-prod+ingress')->node('app-prod'));

        expect(array_column($programs['programs'], 'name'))->toBe(['bootstrap', 'app-prod-prerequisites', 'ingress-prerequisites']);
    });

    it('renders with a throwaway Orbit home and SSH key and none of the harness environment', function () {
        $root = topologyNodeProgramsRoot(renderingArtisan());
        new TopologyNodePrograms($root)->for(TopologyRecipe::fromOption(null)->node('app-prod'));
        $rendered = json_decode((string) file_get_contents($root.'/rendered.json'), true, 8, JSON_THROW_ON_ERROR);

        expect($rendered['argv'])->toBe(['orbit:node-programs', 'app-prod', '--architecture=x86_64', '--json', '--no-interaction'])
            ->and($rendered['orbit_home'])->toBe($rendered['home'])
            ->and($rendered['orbit_home'])->toStartWith(sys_get_temp_dir().'/orbit-e2e-programs-')
            ->and($rendered['key'])->toBe(TopologyNodePrograms::PLACEHOLDER_KEY."\n")
            ->and($rendered['app_key'])->toBeFalse()
            ->and(is_dir($rendered['orbit_home']))->toBeFalse();
    });

    it('fails loudly when the Gateway cannot render or returns an invalid program', function (string $artisan, string $message) {
        $root = topologyNodeProgramsRoot($artisan);

        expect(fn () => new TopologyNodePrograms($root)->for(TopologyRecipe::fromOption(null)->node('app-dev')))->toThrow(RuntimeException::class, $message);
    })->with([
        'command failure' => ["<?php fwrite(STDERR, 'Unknown role [app-dev].'); exit(1);", 'orbit:node-programs app-dev failed: Unknown role [app-dev].'],
        'invalid JSON' => ['<?php echo "not json";', 'returned invalid JSON'],
        'no agent' => ['<?php echo json_encode(["programs" => []]);', 'returned no agent inside the worktree'],
        'an agent outside the worktree' => ['<?php echo json_encode(["agent" => ["path" => "/usr/bin/orbit-agent", "sha256" => str_repeat("a", 64)], "programs" => []]);', 'returned no agent inside the worktree'],
        'no programs' => ['<?php echo json_encode(["agent" => ["path" => dirname(__DIR__, 2)."/a", "sha256" => str_repeat("a", 64)], "programs" => []]);', 'returned no programs'],
        'bad name' => ['<?php echo json_encode(["agent" => ["path" => dirname(__DIR__, 2)."/a", "sha256" => str_repeat("a", 64)], "programs" => [["name" => "Bad Name", "arguments" => ["true"], "input" => null]]]);', 'returned an invalid program'],
        'a fingerprint in another format' => ['<?php echo json_encode(["agent" => ["path" => dirname(__DIR__, 2)."/a", "sha256" => str_repeat("a", 64)], "programs" => [["name" => "ok", "arguments" => ["true"], "input" => null, "fingerprint" => hash("sha256", "other")]]]);', 'fingerprints [ok] differently from the harness'],
        'extra key' => ['<?php echo json_encode(["agent" => ["path" => dirname(__DIR__, 2)."/a", "sha256" => str_repeat("a", 64)], "programs" => [["name" => "ok", "arguments" => ["true"], "input" => null, "protected" => true]]]);', 'returned an invalid program'],
    ]);
});
