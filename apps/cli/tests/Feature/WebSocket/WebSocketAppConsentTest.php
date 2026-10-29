<?php

declare(strict_types=1);

use App\Support\Console\TerminalText;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
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
            ->and($requests)->toHaveCount($confirmed ? count($case['replies']) : 0);

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
    'destroy app' => [[
        'arguments' => ['websocket:app:destroy', 'hauzer'],
        'prompt' => 'Destroy WebSocket app [hauzer]?',
        'question' => 'Destroy WebSocket app [hauzer]? Reverb stops serving it, and its clients disconnect.',
        'endpoint' => '/api/v1/websocket-apps/hauzer',
        'success' => 'Integration succeeded.',
        'cancelled' => 'WebSocket app destruction cancelled.',
        'replies' => [[
            'class' => DestroyWebSocketAppRequest::class,
            'body' => [
                'data' => ['id' => 42, 'kind' => 'websocket.app_destroy', 'target_type' => 'websocket_revision', 'target_id' => 3, 'status' => 'succeeded', 'sequence' => 1],
                'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
            ],
        ], [
            'class' => ShowRealtimeRequest::class,
            'body' => ['data' => []],
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
