<?php

declare(strict_types=1);

use App\Actions\Instances\Dependencies\CollectInstanceDependencyFilesAction;
use App\Domain\Instances\Dependencies\DependencyCollectionException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\Instance;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

// The Gateway's part of dependency collection: the source it names and how it checks the answer. The agent's Rust
// tests in apps/agent/src/commands/dependencies_tests.rs prove how each file is read.

/** @return array<string, mixed> */
function dependency_collection_receipt(string $root = '/home/orbit/project', ?string $reference = null): array
{
    $files = array_fill_keys(CollectInstanceDependencyFilesAction::FILES, ['content' => null, 'hash' => null, 'error' => null]);
    $files['composer.json'] = ['content' => base64_encode('{}'), 'hash' => hash('sha256', '{}'), 'error' => null];

    return ['root' => $root, 'reference' => $reference, 'identity' => str_repeat('a', 64), 'files' => $files];
}

function dependency_collection_instance(bool $production = false): Instance
{
    $instance = new Instance([
        'environment' => $production ? 'production' : 'development',
        'source_layout' => 'checkout', 'checkout_path' => '/home/orbit/project',
        'production_user' => $production ? 'app_sample' : null,
        'production_home' => $production ? '/home/app_sample' : null,
        'root' => 'public',
        'source_is_laravel' => true,
    ]);
    $node = new Node(['user' => 'orbit', 'wireguard_ip' => '10.44.0.2']);
    orbit_test_set_app_placement_role($node, $production);
    $instance->setRelation('node', $node);

    return $instance;
}

/** @param  array<string, mixed>|Closure  $answer */
function dependency_collection_agents(string $command, array|Closure $answer): FakeAgentCommandRunner
{
    $agents = new FakeAgentCommandRunner()->answer($command, $answer);
    app()->instance(AgentCommandRunner::class, $agents);

    return $agents;
}

describe('managed dependency collection', function (): void {
    it('reads the recorded checkout or production release of the application directory', function (bool $production, string $webRoot, string $suffix): void {
        $instance = dependency_collection_instance($production);
        $instance->root = $webRoot;
        $root = ($production ? '/home/app_sample/releases/selected' : '/home/orbit/project').$suffix;
        $command = $production ? 'instances.read-release-dependency-files' : 'instances.read-dependency-files';
        $agents = dependency_collection_agents($command, dependency_collection_receipt($root, $production ? 'selected' : null));

        $files = app(CollectInstanceDependencyFilesAction::class)->execute($instance);

        expect($agents->calls[0]['input'])->toBe([
            'path' => $production ? '/home/app_sample' : '/home/orbit/project', 'application' => $suffix, 'monorepo' => false,
        ])
            ->and($files->projectRoot)->toBe($root)
            ->and($files->contents['composer.json'])->toBe('{}')
            ->and($files->hashes['package.json'])->toBeNull();
    })->with([
        'development public' => [false, 'public', ''],
        'production public' => [true, 'public', ''],
        'development nested' => [false, 'apps/site/public', '/apps/site'],
        'production nested' => [true, 'apps/site/public', '/apps/site'],
    ]);

    it('rejects invalid identities before it contacts the Node', function (array $attributes, bool $production = false): void {
        $instance = dependency_collection_instance();
        if ($production) {
            orbit_test_set_app_placement_role($instance->node, true);
        }
        $instance->forceFill($attributes);
        $agents = dependency_collection_agents('instances.read-dependency-files', []);

        expect(fn () => app(CollectInstanceDependencyFilesAction::class)->execute($instance))
            ->toThrow(DependencyCollectionException::class, 'dependencies.unsafe_source');
        expect($agents->calls)->toBe([]);
    })->with([
        [['checkout_path' => 'relative']],
        [['source_layout' => 'nested']],
        [['production_user' => '-root', 'production_home' => '/home/-root'], true],
        [['production_user' => 'app_sample', 'production_home' => '/tmp/wrong'], true],
    ]);

    it('refuses an answer that does not name the recorded source', function (string $kind, string $code): void {
        $receipt = dependency_collection_receipt();
        match ($kind) {
            'missing-file' => $receipt['files'] = array_diff_key($receipt['files'], ['package.json' => true]),
            'hash' => $receipt['files']['composer.json']['hash'] = str_repeat('b', 64),
            'base64' => $receipt['files']['composer.json']['content'] = '!',
            'path' => $receipt['root'] = '/elsewhere',
            'identity' => $receipt['identity'] = 'https://secret',
            'error' => $receipt = ['error' => 'dependencies.source_changed'],
            'unsafe-error' => $receipt = ['error' => 'password=secret'],
        };
        dependency_collection_agents('instances.read-dependency-files', $receipt);

        expect(fn () => app(CollectInstanceDependencyFilesAction::class)->execute(dependency_collection_instance()))
            ->toThrow(function (DependencyCollectionException $error) use ($code): void {
                expect($error->getMessage())->toBe($code)
                    ->and($error->getPrevious())->toBeNull();
            });
    })->with([
        ['missing-file', 'dependencies.invalid_collection'], ['hash', 'dependencies.invalid_collection'],
        ['base64', 'dependencies.invalid_collection'], ['path', 'dependencies.invalid_collection'],
        ['identity', 'dependencies.invalid_collection'], ['error', 'dependencies.source_changed'],
        ['unsafe-error', 'dependencies.invalid_collection'],
    ]);

    it('retains per-file failures separately from absence', function (): void {
        $receipt = dependency_collection_receipt();
        $receipt['files']['package.json']['error'] = 'dependencies.unreadable_source';
        dependency_collection_agents('instances.read-dependency-files', $receipt);

        $files = app(CollectInstanceDependencyFilesAction::class)->execute(dependency_collection_instance());

        expect($files->errors)->toBe(['package.json' => 'dependencies.unreadable_source'])
            ->and($files->contents['composer.json'])->toBe('{}');
    });

    it('discards the text of a runner failure', function (): void {
        dependency_collection_agents('instances.read-dependency-files', fn (): array => throw new AgentRunnerFailure('instances.read-dependency-files', AgentRunnerFailure::Transport, 'password=secret'));

        expect(fn () => app(CollectInstanceDependencyFilesAction::class)->execute(dependency_collection_instance()))
            ->toThrow(function (DependencyCollectionException $error): void {
                expect($error->getMessage())->toBe('dependencies.unreadable_source')
                    ->and($error->getPrevious())->toBeNull();
            });
    });
});
