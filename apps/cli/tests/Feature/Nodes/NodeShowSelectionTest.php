<?php

declare(strict_types=1);

use App\Support\Console\TerminalText;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Symfony\Component\Process\Process;

it('selects an omitted Node by its stable ID and restores the terminal', function (array $keys, bool $selected): void {
    $directory = sys_get_temp_dir().'/orbit-node-show-selection-'.Str::uuid();
    new Filesystem()->ensureDirectoryExists($directory);
    $configuration = [
        'arguments' => ['node:show'],
        'home' => $directory.'/home',
        'trace' => $directory.'/requests.jsonl',
        'prompt' => 'same-name',
        'keys' => array_map(base64_encode(...), $keys),
        'columns' => 100,
        'replies' => [[
            'class' => ListNodesRequest::class,
            'body' => ['data' => [
                ['id' => 17, 'name' => 'same-name', 'status' => 'failed', 'platform' => 'linux'],
                ['id' => 23, 'name' => 'same-name', 'status' => 'inactive', 'platform' => 'ipados'],
            ]],
        ], [
            'class' => ShowNodeRequest::class,
            'body' => ['data' => ['id' => 23, 'name' => 'same-name', 'status' => 'inactive']],
        ]],
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
        $requests = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($configuration['trace'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        expect($result['status'])->toBe($selected ? 0 : 1, $output)
            ->and($result['prompt_seen'])->toBeTrue()
            ->and($result['keys_remaining'])->toBe(0)
            ->and($result['restored'])->toBeTrue()
            ->and($output)->toContain('Node', 'ID', 'Name', '17', '23', 'same-name', '/ Search')
            ->and($requests)->toHaveCount($selected ? 2 : 1)
            ->and($requests[0]['class'])->toBe(ListNodesRequest::class)
            ->and($requests[0]['method'])->toBe('GET');

        if ($selected) {
            expect($requests[1])->toBe([
                'class' => ShowNodeRequest::class,
                'method' => 'GET',
                'url' => 'https://fixture.invalid/api/v1/nodes/23',
                'body' => [],
            ])->and($output)->toContain('Node: same-name', 'inactive');
        } else {
            expect($output)->toContain('Input cancelled.')->not->toContain('Node: same-name');
        }
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
})->with([
    'arrow selection' => [["\e[B", "\r"], true],
    'search by ID' => [['2', '3', "\r"], true],
    'Ctrl-C cancellation' => [["\x03"], false],
    'Ctrl-D cancellation' => [["\x04"], false],
]);
