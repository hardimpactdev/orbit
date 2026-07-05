<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\AddDatabaseUser;
use App\Enums\Processes\ProcessRuntime;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Process;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    request()->headers->set(
        ExplicitRemoteShellFallback::HEADER,
        NodeTransportPreference::AgentPush->value,
    );
});

it('adds database users through the agent-push local executor by default', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.71:9477/v1/commands' => Http::response([
            'transport' => 'agent-push',
            'operation_id' => 'database.add-user',
            'binary' => 'orbit',
            'status' => 'succeeded',
            'exit_code' => 0,
            'frames' => [
                [
                    'type' => 'stdout',
                    'message' => "{\"success\":{\"data\":{\"ok\":true},\"meta\":[]}}\n",
                ],
                [
                    'type' => 'exit',
                    'message' => '0',
                ],
            ],
        ]),
    ]);

    $node = Node::factory()
        ->appDev()
        ->orbitAgentCapable()
        ->create([
            'name' => 'beast',
            'wireguard_address' => '10.44.0.71',
        ]);
    $process = Process::factory()
        ->forOwner($node, $node)
        ->create([
            'name' => 'mysql-main',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'service' => 'mysql',
                'endpoint' => [
                    'host' => 'mysql-main.internal',
                    'port' => 3306,
                ],
            ],
        ]);

    $result = app(AddDatabaseUser::class)->handle(
        process: $process,
        connection: 'app-db',
        database: 'app_database',
        username: 'app_user',
        password: 'secret-password',
    );

    expect($result)
        ->toBeInstanceOf(DatabaseConnection::class)
        ->and($result->slug)
        ->toBe('app-db')
        ->and($result->node_id)
        ->toBe($node->id);

    Http::assertSent(function (Request $request): bool {
        $input = json_decode((string) $request['input'], true);

        return (
            $request->url() === 'http://10.44.0.71:9477/v1/commands'
            && $request['binary'] === 'orbit'
            && $request['argv'][0] === 'internal:database-add-user'
            && str_starts_with((string) $request['argv'][1], '--operation-token=')
            && $request['argv'][2] === '--json'
            && $request['timeout_seconds'] === 120
            && $request['stream'] === true
            && $input === [
                'container' => 'mysql-main',
                'database' => 'app_database',
                'username' => 'app_user',
                'password' => 'secret-password',
            ]
        );
    });
});
