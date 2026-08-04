<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\ProcessEventType;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessEvent;
use App\Models\Project;
use App\Models\ProxyRoute;
use App\Services\Processes\ProcessStreamRuntimeConfig;
use App\Services\Processes\ProcessStreamSleeper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\NullRemoteShell;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
});

const PROCESS_KEY_LABEL_WG_IP = '10.6.0.93';

/**
 * @return array{caller: Node, appNode: Node, app: Project, instance: AppInstance, process: Process}
 */
function processKeyLabelFixture(array $processAttributes = []): array
{
    $caller = Node::factory()->create([
        'name' => 'caller',
        'host' => PROCESS_KEY_LABEL_WG_IP,
        'wireguard_address' => PROCESS_KEY_LABEL_WG_IP,
    ]);
    $appNode = createTestAppHostNode(['name' => 'app-1']);
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['process:read', 'process:add', 'process:update'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
    $instance = AppInstance::factory()->create([
        'app_id' => $app->id,
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $appNode->id),
    ]);
    $process = Process::factory()
        ->forOwner($app, $appNode)
        ->create(array_merge([
            'app_instance_id' => $instance->id,
            'name' => 'vite',
            'command' => 'npm run dev',
        ], $processAttributes));

    return compact('caller', 'appNode', 'app', 'instance', 'process');
}

describe('process key and label contract', function (): void {
    it('defaults new process label to key/name when label is omitted', function (): void {
        $fixture = processKeyLabelFixture();
        Process::query()->whereKey($fixture['process']->id)->delete();
        app()->instance(RemoteShell::class, new NullRemoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs.development',
                'name' => 'queue',
                'command' => 'php artisan queue:work',
                'runtime' => 'systemd',
                'no_start' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_KEY_LABEL_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.key', 'queue')
            ->assertJsonPath('success.data.process.label', 'queue')
            ->assertJsonPath('success.data.process.name', 'queue');

        expect(Process::query()->where('name', 'queue')->value('label'))->toBe('queue');
    });

    it('persists an explicit process label on create', function (): void {
        $fixture = processKeyLabelFixture();
        Process::query()->whereKey($fixture['process']->id)->delete();
        app()->instance(RemoteShell::class, new NullRemoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs.development',
                'name' => 'queue',
                'label' => 'Queue Worker',
                'command' => 'php artisan queue:work',
                'runtime' => 'systemd',
                'no_start' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_KEY_LABEL_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.key', 'queue')
            ->assertJsonPath('success.data.process.label', 'Queue Worker')
            ->assertJsonPath('success.data.process.name', 'queue');

        expect(Process::query()->where('name', 'queue')->value('label'))->toBe('Queue Worker');
    });

    it('updates label without changing identity and preserves label on rename', function (): void {
        $fixture = processKeyLabelFixture([
            'name' => 'vite',
            'label' => 'Vite Dev Server',
        ]);
        app()->instance(RemoteShell::class, new NullRemoteShell);

        $labelResponse = $this->call(
            'PATCH',
            '/api/processes/vite',
            [
                'instance' => 'docs.development',
                'label' => 'Frontend Dev',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_KEY_LABEL_WG_IP],
        );

        $labelResponse
            ->assertOk()
            ->assertJsonPath('success.data.process.key', 'vite')
            ->assertJsonPath('success.data.process.label', 'Frontend Dev')
            ->assertJsonPath('success.data.process.name', 'vite')
            ->assertJsonPath('success.data.changed', ['label']);

        $renameResponse = $this->call(
            'PATCH',
            '/api/processes/vite',
            [
                'instance' => 'docs.development',
                'name' => 'frontend',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_KEY_LABEL_WG_IP],
        );

        $renameResponse
            ->assertOk()
            ->assertJsonPath('success.data.process.key', 'frontend')
            ->assertJsonPath('success.data.process.label', 'Frontend Dev')
            ->assertJsonPath('success.data.process.name', 'frontend')
            ->assertJsonPath('success.data.changed', ['name']);

        expect(Process::query()->where('name', 'frontend')->value('label'))->toBe('Frontend Dev');
    });

    it('exposes key label and deprecated name on process list', function (): void {
        processKeyLabelFixture([
            'name' => 'vite',
            'label' => 'Vite Dev Server',
        ]);

        $response = $this->call(
            'GET',
            '/api/processes?instance=docs.development',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_KEY_LABEL_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.processes.0.key', 'vite')
            ->assertJsonPath('success.data.processes.0.label', 'Vite Dev Server')
            ->assertJsonPath('success.data.processes.0.name', 'vite');
    });

    it('includes current label on stream updates only when related process key matches durable key', function (): void {
        $fixture = processKeyLabelFixture([
            'name' => 'vite',
            'label' => 'Vite Dev Server',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $fixture['appNode']->id,
            'domain' => 'test.app.example',
            'app_id' => $fixture['app']->id,
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'app_instance' => [
                    'name' => 'development',
                    'selector' => 'docs.development',
                ],
            ],
        ]);

        $baseline = ProcessEvent::factory()->create([
            'event' => ProcessEventType::Started,
            'process_id' => $fixture['process']->id,
            'process_name' => 'vite',
            'app_id' => $fixture['app']->id,
            'app_instance_id' => $fixture['instance']->id,
            'workspace_id' => null,
            'node_id' => $fixture['appNode']->id,
            'unit_name' => 'orbit_docs_development_main_vite',
        ]);

        $createdIds = [];
        app()->instance(ProcessStreamSleeper::class, new class($fixture, $createdIds) implements ProcessStreamSleeper {
            /**
             * @param  array{process: Process, app: Project, instance: AppInstance, appNode: Node}  $fixture
             * @param  list<int>  $createdIds
             */
            public function __construct(
                private array $fixture,
                private array &$createdIds,
            ) {}

            public function sleep(int $microseconds): void
            {
                if ($this->createdIds === []) {
                    // Matching current process: update should carry current label.
                    $match = ProcessEvent::factory()->create([
                        'event' => ProcessEventType::Stopping,
                        'process_id' => $this->fixture['process']->id,
                        'process_name' => 'vite',
                        'app_id' => $this->fixture['app']->id,
                        'app_instance_id' => $this->fixture['instance']->id,
                        'workspace_id' => null,
                        'node_id' => $this->fixture['appNode']->id,
                        'unit_name' => 'orbit_docs_development_main_vite',
                    ]);
                    $this->createdIds[] = $match->id;

                    return;
                }

                if (count($this->createdIds) === 1) {
                    // Rename so a later event with the old durable key must not use the new label.
                    $this->fixture['process']->forceFill([
                        'name' => 'frontend',
                        'label' => 'Frontend After Rename',
                    ])->save();

                    $stale = ProcessEvent::factory()->create([
                        'event' => ProcessEventType::Stopped,
                        'process_id' => $this->fixture['process']->id,
                        'process_name' => 'vite',
                        'app_id' => $this->fixture['app']->id,
                        'app_instance_id' => $this->fixture['instance']->id,
                        'workspace_id' => null,
                        'node_id' => $this->fixture['appNode']->id,
                        'unit_name' => 'orbit_docs_development_main_vite',
                    ]);
                    $this->createdIds[] = $stale->id;
                }
            }
        });
        app()->instance(
            ProcessStreamRuntimeConfig::class,
            new ProcessStreamRuntimeConfig(
                pollMicroseconds: 0,
                heartbeatMicroseconds: 1_000_000_000,
                maxIdlePolls: 3,
            ),
        );

        $content = $this->call(
            'GET',
            '/api/processes/stream',
            ['app' => 'test.app.example'],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_KEY_LABEL_WG_IP],
        )->streamedContent();

        expect($content)
            ->toContain("id: {$baseline->id}\n")
            ->toContain("event: snapshot\n")
            ->toContain('"key":"vite"')
            ->toContain('"label":"Vite Dev Server"')
            ->toContain("event: update\n");

        // Matching key carries current label.
        expect($content)->toContain('"event":"stopping"');
        expect($content)->toMatch('/"event":"stopping"[^\\n]*"key":"vite"/s');

        // After rename, durable old key falls back to key for label (not Frontend After Rename).
        expect($content)
            ->toContain('"event":"stopped"')
            ->toContain('"key":"vite"')
            ->not->toContain('"label":"Frontend After Rename"');
    });

    it('rejects empty and overlong labels on create and update', function (): void {
        processKeyLabelFixture();

        $this
            ->call(
                'POST',
                '/api/processes',
                [
                    'instance' => 'docs.development',
                    'name' => 'queue',
                    'label' => '   ',
                    'command' => 'php artisan queue:work',
                ],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_KEY_LABEL_WG_IP],
            )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'label');

        $this
            ->call(
                'PATCH',
                '/api/processes/vite',
                [
                    'instance' => 'docs.development',
                    'label' => str_repeat('a', 256),
                ],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_KEY_LABEL_WG_IP],
            )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'label');
    });
});
