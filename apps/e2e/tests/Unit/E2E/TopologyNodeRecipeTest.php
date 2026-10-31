<?php

declare(strict_types=1);

use App\E2E\TopologyNodeRecipe;
use App\E2E\Value\TopologyRecipe;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

function topologyNodeRecipeRoot(string $artisan): string
{
    $root = temporaryPath('orbit-node-recipe-', 5);
    mkdir($root.'/apps/gateway', 0700, true);
    file_put_contents($root.'/apps/gateway/artisan', $artisan);

    return $root;
}

function recipeArtisan(string $change = ''): string
{
    return <<<'PHP'
        <?php
        $roles = array_values(array_filter(array_slice($argv, 2), static fn (string $arg): bool => ! str_starts_with($arg, '--')));
        file_put_contents(dirname(__DIR__, 2).'/rendered.json', json_encode(['argv' => array_slice($argv, 1), 'home' => getenv('HOME'), 'orbit_home' => getenv('ORBIT_HOME'), 'key' => file_get_contents(getenv('ORBIT_HOME').'/ssh/id_ed25519.pub'), 'app_key' => getenv('APP_KEY')]));
        $data = ['schema_version' => 1, 'node' => ['platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'home' => '/home/orbit', 'roles' => $roles], 'bootstrap' => ['user' => 'orbit', 'public_key' => 'ssh-ed25519 AAAA'], 'recipe' => ['tools' => []], 'agent' => ['path' => dirname(__DIR__, 2).'/apps/agent/delivery/orbit-agent', 'sha256' => str_repeat('a', 64)]];
        PHP
        .$change.'echo json_encode($data);';
}

describe('TopologyNodeRecipe', function (): void {
    beforeEach(function (): void {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        putenv('APP_KEY=fixture-private-value');
    });
    afterEach(fn () => putenv('APP_KEY'));

    it('reads exact Node metadata and never returns executable programs', function (): void {
        $root = topologyNodeRecipeRoot(recipeArtisan());
        $metadata = new TopologyNodeRecipe($root)->for(TopologyRecipe::fromOption(null)->node('gateway'));
        expect($metadata['node']['roles'])->toBe(['gateway', 'vpn'])
            ->and($metadata['agent'])->toBe(['path' => '/home/orbit/orbit/apps/agent/delivery/orbit-agent', 'sha256' => str_repeat('a', 64)])
            ->and($metadata)->not->toHaveKeys(['programs', 'arguments', 'script']);
        $call = json_decode(file_get_contents($root.'/rendered.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($call['argv'])->toBe(['orbit:node-recipe', 'gateway', 'vpn', '--architecture=x86_64', '--json', '--no-interaction'])
            ->and($call['home'])->toBe($call['orbit_home'])->toStartWith(sys_get_temp_dir().'/orbit-e2e-recipe-')
            ->and($call['key'])->toBe(TopologyNodeRecipe::PLACEHOLDER_KEY."\n")
            ->and($call['app_key'])->toBeFalse()->and(is_dir($call['home']))->toBeFalse();
    });

    it('refuses malformed metadata, executable exports and an observer outside the worktree', function (string $change): void {
        $root = topologyNodeRecipeRoot(recipeArtisan($change));
        expect(fn () => new TopologyNodeRecipe($root)->for(TopologyRecipe::fromOption(null)->node('gateway')))->toThrow(RuntimeException::class);
    })->with([
        '$data["node"]["user"]="foreign";', '$data["node"]["roles"]=["vpn"];', '$data["programs"]=[["arguments"=>["false"]]];',
        '$data["bootstrap"]=[];', '$data["agent"]["path"]="/usr/local/bin/orbit-agent";',
        '$data["agent"]["path"]=dirname(__DIR__, 2)."/../private";', '$data["agent"]["sha256"]="invalid";',
    ]);

    it('reports a failed or invalid metadata command', function (string $artisan): void {
        $root = topologyNodeRecipeRoot($artisan);
        expect(fn () => new TopologyNodeRecipe($root)->for(TopologyRecipe::fromOption(null)->node('gateway')))->toThrow(RuntimeException::class);
    })->with(['<?php fwrite(STDERR,"failed"); exit(1);', '<?php echo "invalid";']);
});
