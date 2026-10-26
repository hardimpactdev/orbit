<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\Transfer\TransferSourceCapture;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentBinaryInput;
use App\Infrastructure\AgentCommands\AgentBinaryOutput;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\Instances\RemoteInstanceTransferSource;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\ProcessInput;

it('stages private binary bytes on Gateway disk and removes them after materialization', function (): void {
    [$capture, $destination] = remote_transfer_capture();
    $agents = new FakeAgentCommandRunner;
    $bytes = "archive\0\xffcontents";
    $staged = null;
    $sink = null;
    $agents->answer('instance.read-transfer-archive', function (array $input, Node $node, ?AgentBinaryOutput $output) use ($bytes, &$staged, &$sink): array {
        expect($node->name)->toBe('staging-source')->and($input)->toBe(['archive' => '/tmp/orbit-transfer-source.tar']);
        $sink = $output;
        expect($output?->write($bytes))->toBeTrue();
        $paths = glob(storage_path('app/transfer-staging/orbit-transfer-*')) ?: [];
        $staged = array_find($paths, static fn (string $path): bool => file_get_contents($path) === $bytes);
        expect($staged)->toBeString()->and(fileperms($staged) & 0777)->toBe(0600);

        return ['bytes' => strlen($bytes)];
    });
    $agents->answer('instance.materialize-transfer', function (array $input, Node $node, ?AgentBinaryOutput $output, ?AgentBinaryInput $binary) use ($bytes, $capture): array {
        expect($node->name)->toBe('staging-destination')->and($input)->toBe([
            'destination' => '/srv/apps/destination', 'head' => $capture->head, 'branch' => 'main',
            'detached' => false, 'environment' => '/srv/apps/destination/.env',
        ]);
        $body = $binary?->envelope('');
        expect($body)->not->toBeNull()->and(ProcessInput::contents($body))->toBe($bytes);

        return [];
    });

    $checkout = new RemoteInstanceTransferSource($agents, app(AgentContract::class))
        ->materialize($capture, $destination, StoragePath::parse('/srv/apps/destination'));

    expect($checkout->head)->toBe($capture->head)
        ->and($agents->commands())->toBe(['instance.read-transfer-archive', 'instance.materialize-transfer'])
        ->and(file_exists($staged))->toBeFalse()->and($sink?->write('closed'))->toBeFalse();
});

it('removes partial staging and redacts download failures', function (): void {
    [$capture, $destination] = remote_transfer_capture();
    $before = glob(storage_path('app/transfer-staging/orbit-transfer-*')) ?: [];
    $agents = new FakeAgentCommandRunner;
    $agents->answer('instance.read-transfer-archive', function (array $input, Node $node, ?AgentBinaryOutput $output): array {
        $output?->write('partial private archive');
        throw new RuntimeException('PRIVATE_TRANSPORT_DIAGNOSTIC');
    });
    Log::spy();

    expect(fn () => new RemoteInstanceTransferSource($agents, app(AgentContract::class))
        ->materialize($capture, $destination, StoragePath::parse('/srv/apps/destination')))
        ->toThrow(function (ResourceOperationException $error): void {
            expect($error->errorCode)->toBe('instance.transfer_failed')->and($error->getPrevious())->toBeNull();
        });

    expect(glob(storage_path('app/transfer-staging/orbit-transfer-*')) ?: [])->toBe($before)
        ->and($agents->commands())->toBe(['instance.read-transfer-archive']);
    Log::shouldHaveReceived('warning')->once()->with('Instance transfer archive staging failed.', [
        'step' => 'app-instance-transfer-download', 'exit_code' => null,
    ]);
});

it('maps materialization refusals to the operation step and removes the staging file', function (): void {
    [$capture, $destination] = remote_transfer_capture();
    $before = glob(storage_path('app/transfer-staging/orbit-transfer-*')) ?: [];
    $agents = new FakeAgentCommandRunner;
    $agents->answer('instance.read-transfer-archive', function (array $input, Node $node, ?AgentBinaryOutput $output): array {
        $output?->write('archive');

        return ['bytes' => 7];
    })->refuse('instance.materialize-transfer', 'instance.transfer_archive_invalid');

    expect(fn () => new RemoteInstanceTransferSource($agents, app(AgentContract::class))
        ->materialize($capture, $destination, StoragePath::parse('/srv/apps/destination')))
        ->toThrow(function (RuntimeConvergenceException $error): void {
            expect($error->errorCode)->toBe('instance.transfer_failed')->and($error->getPrevious())->toBeInstanceOf(AgentCommandRefused::class);
        });
    expect(glob(storage_path('app/transfer-staging/orbit-transfer-*')) ?: [])->toBe($before);
});

/** @return array{TransferSourceCapture, Node} */
function remote_transfer_capture(): array
{
    $node = static fn (string $name): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit',
        'public_ssh_host' => '127.0.0.1', 'wireguard_ip' => '127.0.0.'.(Node::query()->count() + 1),
    ]);
    $source = $node('staging-source');
    $destination = $node('staging-destination');
    $project = Project::query()->create(['name' => 'Transfer', 'slug' => 'transfer', 'repository_url' => 'https://example.test/transfer.git']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $source->id, 'name' => 'source', 'checkout_path' => '/srv/apps/source']);

    return [new TransferSourceCapture(
        instanceId: $instance->id, nodeId: $source->id, layout: InstanceSourceLayout::Checkout,
        sourcePath: '/srv/apps/source', commonRepositoryPath: null, head: str_repeat('a', 40), branch: 'main', detached: false,
        archiveIdentity: '/tmp/orbit-transfer-source.tar', refs: ['main'],
    ), $destination];
}

it('selects the transferred Laravel environment from Instance or Project intent', function (?string $instanceRoot, string $projectRoot, bool $laravel, string $suffix): void {
    [$capture, $destination] = remote_transfer_capture();
    $instance = Instance::query()->findOrFail($capture->instanceId);
    $instance->update(['root' => $instanceRoot, 'source_is_laravel' => $laravel]);
    $instance->project->update(['root' => $projectRoot]);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('instance.read-transfer-archive', function (array $input, Node $node, ?AgentBinaryOutput $output): array {
        $output?->write('archive');

        return ['bytes' => 7];
    })->answer('instance.materialize-transfer', []);

    new RemoteInstanceTransferSource($agents, app(AgentContract::class))
        ->materialize($capture, $destination, StoragePath::parse('/srv/apps/destination'));

    expect($agents->calls[1]['input']['environment'])->toBe('/srv/apps/destination'.$suffix.'/.env');
})->with([
    'root public' => ['public', 'public', true, ''],
    'nested Instance' => ['server/web/public', 'public', true, '/server/web'],
    'nested Project' => [null, 'server/web/public', true, '/server/web'],
    'non Laravel' => ['server/web/public', 'public', false, ''],
]);
