<?php

declare(strict_types=1);

use App\Support\Console\TerminalText;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Instances\UnsetInstancePathProxyRequest;
use Orbit\Sdk\Requests\WebSocket\DestroyWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\RemoveInstanceWebSocketAppRequest;
use Symfony\Component\Process\Process;

it('requires affirmative consent and restores the terminal before returning', function (array $case, array $keys, bool $confirmed): void {
    $directory = sys_get_temp_dir().'/orbit-websocket-consent-'.Str::uuid();
    new Filesystem()->ensureDirectoryExists($directory);
    $configuration = [
        ...$case,
        'home' => $directory.'/home',
        'trace' => $directory.'/requests.jsonl',
        'keys' => array_map(base64_encode(...), $keys),
        'columns' => 80,
    ];
    file_put_contents($directory.'/case.json', json_encode($configuration, JSON_THROW_ON_ERROR));

    try {
        $fixtures = dirname(__DIR__, 2).'/Fixtures/Console/';
        $process = new Process([
            'python3', $fixtures.'command-consent-pty.py',
            PHP_BINARY, $fixtures.'command-consent.php', $directory.'/case.json',
        ], timeout: 20);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $output = TerminalText::plain(base64_decode($result['raw'], strict: true));
        $requests = is_file($configuration['trace'])
            ? file($configuration['trace'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            : [];

        expect($result['status'])->toBe($confirmed ? 0 : 1, $output)
            ->and($result['prompt_seen'])->toBeTrue()
            ->and($result['keys_remaining'])->toBe(0)
            ->and($result['restored'])->toBeTrue()
            ->and($requests)->toHaveCount($confirmed ? 1 : 0);

        expect(preg_replace('/[\s│]/u', '', $output))
            ->toContain(str_replace(' ', '', $case['question']));

        if ($confirmed) {
            $request = json_decode($requests[0], true, flags: JSON_THROW_ON_ERROR);
            expect($request)->toBe([
                'class' => $case['replies'][0]['class'],
                'method' => 'DELETE',
                'url' => 'https://fixture.invalid'.$case['endpoint'],
                'body' => [],
            ]);
            expect($output)->toContain($case['success']);
        } else {
            expect($output)->toContain($case['cancelled']);
        }
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
})->with([
    'remove path proxy' => [[
        'arguments' => ['instance:path-proxy:unset', '--instance=71'],
        'prompt' => 'Remove the path proxy from Instance [71]?',
        'question' => 'Remove the path proxy from Instance [71]? Its public endpoint stops serving the Process.',
        'endpoint' => '/api/v1/instances/71/path-proxy',
        'success' => 'Path proxy removed.',
        'cancelled' => 'Path proxy removal cancelled.',
        'replies' => [[
            'class' => UnsetInstancePathProxyRequest::class,
            'body' => ['data' => ['instance_id' => 71], 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']],
        ]],
    ]],
    'destroy app' => [[
        'arguments' => ['websocket:app:destroy', 'hauzer'],
        'prompt' => 'Destroy WebSocket app [hauzer]?',
        'question' => 'Destroy WebSocket app [hauzer]? Reverb stops serving it, and its clients disconnect.',
        'endpoint' => '/api/v1/websocket-apps/hauzer',
        'success' => 'WebSocket change applied.',
        'cancelled' => 'WebSocket app destruction cancelled.',
        'replies' => [[
            'class' => DestroyWebSocketAppRequest::class,
            'body' => [
                'data' => ['id' => 2, 'name' => 'hauzer', 'app_id' => 'hauzerappid000000001', 'key' => 'hauzerappkey00000001', 'instance_ids' => []],
                'operation' => ['id' => '11111111-1111-4111-8111-111111111111', 'epoch' => '22222222-2222-4222-8222-222222222222', 'revision' => 3, 'state' => 'applied', 'processes' => [], 'missing_node_ids' => []],
                'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
            ],
        ]],
    ]],
    'remove attachment' => [[
        'arguments' => ['instance:websocket:remove', 'hauzer', '--instance=12'],
        'prompt' => 'Remove WebSocket app [hauzer] from Instance [12]',
        'question' => 'Remove WebSocket app [hauzer] from Instance [12] and clear its stored BROADCAST_CONNECTION and REVERB_* keys? Workload .env stays unchanged.',
        'endpoint' => '/api/v1/instances/12/websocket-apps/hauzer',
        'success' => 'WebSocket app [hauzer] removed from Instance [12].',
        'cancelled' => 'WebSocket app removal cancelled.',
        'replies' => [[
            'class' => RemoveInstanceWebSocketAppRequest::class,
            'body' => [
                'data' => [
                    'instance_id' => 12, 'name' => 'hauzer', 'keys' => ['BROADCAST_CONNECTION', 'REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET', 'REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME'],
                    'operation' => 'detach', 'changed' => true, 'key_count' => 0,
                ],
                'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
            ],
        ]],
    ]],
])->with([
    'default No' => [["\r"], false],
    'explicit No' => [['y', 'n', "\r"], false],
    'Ctrl-C' => [["\x03"], false],
    'EOF' => [["\x04"], false],
    'explicit Yes' => [['y', "\r"], true],
]);
