<?php

declare(strict_types=1);

use App\Actions\Apps\SetupApp;
use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\AppSetupStep;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('passes Laravel Vite URL and dev server certificate environment into app setup steps', function (): void {
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'user' => 'nckrtl',
        ]);

    $app = App::factory()->create([
        'name' => 'craft-starterkit-react',
        'php_version' => '8.5',
    ]);

    $instance = Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/nckrtl/apps/craft-starterkit-react',
            document_root: null,
            domain: 'craft-starterkit-react.test',
        ),
    ]);

    AppSetupStep::factory()->for($instance, 'instance')->create([
        'command' => 'npm install',
        'sort_order' => 1,
    ]);

    $shell = new class implements RemoteShell {
        /**
         * @var list<array{node: int|null, script: string, options: array<string, mixed>}>
         */
        public array $runs = [];

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            $this->runs[] = [
                'node' => $node->id,
                'script' => $script,
                'options' => $options,
            ];

            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }
    };
    app()->instance(RemoteShell::class, $shell);

    app(SetupApp::class)->handle($app, $instance, $node);

    expect($shell->runs)
        ->toHaveCount(1)
        ->and($shell->runs[0]['options']['environment'])
        ->toMatchArray([
            'APP_URL' => 'https://craft-starterkit-react.test',
            'VITE_APP_URL' => 'https://craft-starterkit-react.test',
            'VITE_VALET_HOST' => 'craft-starterkit-react.test',
            'VITE_DEV_SERVER_KEY' => '/home/nckrtl/.config/orbit/certs/craft-starterkit-react.test.key',
            'VITE_DEV_SERVER_CERT' => '/home/nckrtl/.config/orbit/certs/craft-starterkit-react.test.crt',
        ]);
});
