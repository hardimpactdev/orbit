<?php

declare(strict_types=1);

use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Tasks\TaskWorkspaceMetadata;
use Illuminate\Support\Facades\File;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\StandardLibraryPython;
use Tests\Support\TurnMetadataSshExecutor;

beforeAll(fn () => TurnMetadataSshExecutor::prepare());
afterAll(fn () => TurnMetadataSshExecutor::forget());

/** @param array<string, mixed> $payload */
function turn_metadata_fixture_command(string $checkout, string $operation, array $payload = []): RemoteCommand
{
    return new RemoteCommand(
        arguments: ['bash', '-seu', '--', $checkout],
        input: "checkout=\$1\n".TaskWorkspaceMetadata::bashPreamble().TaskWorkspaceMetadata::operation($operation, $payload),
    );
}

function turn_metadata_fixture_connection(): SshConnection
{
    return new SshConnection('unused', 'fixture', 22, '/unused/key', '/unused/hosts');
}

it('matches native metadata for each independent operation', function (string $case): void {
    $root = sys_get_temp_dir().'/orbit-turn-metadata-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $native = new LocalShellSshExecutor;
    $native->environment['PATH'] = StandardLibraryPython::path((string) getenv('PATH'));
    $script = File::get(resource_path('tasks/turn'));
    $turn = json_encode(['role' => 'implementer', 'thread' => 23, 'delivery_key' => 'same-key', 'deliverables' => [['id' => 'file', 'type' => 'file', 'description' => '☄ retained file.']]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $context = "all context\0☄\n\n";
    $receipt = "{\"summary\":\"☄\"}\n\0\n\n";
    $snapshots = [];
    try {
        foreach ([$native, new TurnMetadataSshExecutor] as $index => $transport) {
            $checkout = $root.'/checkout-'.$index;
            mkdir($checkout, 0700);
            mkdir($checkout.'/.git', 0700);
            $connection = turn_metadata_fixture_connection();
            $prepared = $transport->execute($connection, turn_metadata_fixture_command($checkout, 'turn', ['script' => base64_encode($script), 'turn' => $turn, 'context' => $context]));
            expect($prepared->exitCode)->toBe(0)->and($prepared->stdout)->toBe('')->and($prepared->stderr)->toBe('');
            $snapshot = [];
            foreach (['turn', 'turn.json', 'context.md'] as $file) {
                $path = $checkout.'/.git/orbit/'.$file;
                $snapshot[$file] = [File::get($path), fileperms($path) & 0777];
            }
            $snapshots[] = $snapshot;
            expect($snapshot)->toBe(['turn' => [$script, 0755], 'turn.json' => [$turn."\n", 0644], 'context.md' => [$context, 0644]]);
            switch ($case) {
                case 'publication':
                    break;
                case 'keyed replay':
                    File::put($checkout.'/.git/orbit/receipt.json', $receipt);
                    $replay = $transport->execute($connection, turn_metadata_fixture_command($checkout, 'turn', ['script' => base64_encode('must not replace the executable'), 'turn' => $turn, 'context' => 'must not replace context']));
                    expect($replay->exitCode)->toBe(0)
                        ->and(File::get($checkout.'/.git/orbit/turn'))->toBe($script)
                        ->and(File::get($checkout.'/.git/orbit/context.md'))->toBe($context)
                        ->and(File::get($checkout.'/.git/orbit/receipt.json'))->toBe($receipt);
                    break;
                case 'reads':
                    expect($transport->execute($connection, turn_metadata_fixture_command($checkout, 'legacy'))->stdout)->toBe("turn\n".$turn."\n");
                    expect($transport->execute($connection, turn_metadata_fixture_command($checkout, 'read'))->stdout)->toBe("none\n");
                    File::put($checkout.'/.git/orbit/receipt.json', $receipt);
                    expect($transport->execute($connection, turn_metadata_fixture_command($checkout, 'read'))->stdout)->toBe("receipt\n".$turn."\n".$receipt);
                    break;
                case 'conditional clearing':
                    File::put($checkout.'/.git/orbit/receipt.json', $receipt);
                    expect($transport->execute($connection, turn_metadata_fixture_command($checkout, 'clear', ['hash' => hash('sha256', 'wrong receipt')]))->exitCode)->toBe(0);
                    expect(File::get($checkout.'/.git/orbit/receipt.json'))->toBe($receipt);
                    expect($transport->execute($connection, turn_metadata_fixture_command($checkout, 'clear', ['hash' => hash('sha256', $receipt)]))->exitCode)->toBe(0);
                    expect(is_file($checkout.'/.git/orbit/receipt.json'))->toBeFalse();
                    break;
                case 'symlink refusal':
                    $outside = $root.'/outside-'.$index;
                    File::put($outside, $turn);
                    unlink($checkout.'/.git/orbit/turn.json');
                    symlink($outside, $checkout.'/.git/orbit/turn.json');
                    $refused = $transport->execute($connection, turn_metadata_fixture_command($checkout, 'legacy'));
                    expect($refused->exitCode)->toBe(1)->and($refused->stdout)->toBe('')
                        ->and($refused->stderr)->toContain('Too many levels of symbolic links')
                        ->and(File::get($outside))->toBe($turn);
                    break;
                default:
                    throw new LogicException('Unexpected metadata parity scenario.');
            }
        }
        expect($snapshots[0])->toBe($snapshots[1]);
    } finally {
        File::deleteDirectory($root);
    }
})->with(['publication', 'keyed replay', 'reads', 'conditional clearing', 'symlink refusal']);

it('rejects extra shell source instead of running a partial metadata program', function (): void {
    $directory = sys_get_temp_dir().'/orbit-metadata-reject-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $command = turn_metadata_fixture_command($directory, 'legacy');
    try {
        expect(fn () => (new TurnMetadataSshExecutor)->execute(turn_metadata_fixture_connection(), new RemoteCommand(
            arguments: $command->arguments,
            input: $command->input.'touch '.escapeshellarg($directory.'/must-not-run')."\n",
        )))->toThrow(LogicException::class, 'Only exact generated turn metadata commands belong to this fixture.');
        expect(is_file($directory.'/must-not-run'))->toBeFalse()->and(is_dir($directory.'/.git'))->toBeFalse();
    } finally {
        File::deleteDirectory($directory);
    }
});

it('rejects callbacks rather than replacing streaming or cancellation proofs', function (string $kind): void {
    $callbacks = $kind === 'output' ? ['output' => static fn () => null] : ['cancelled' => static fn (): bool => false];
    expect(fn () => (new TurnMetadataSshExecutor)->execute(turn_metadata_fixture_connection(), new RemoteCommand(...['arguments' => ['bash', '-seu'], ...$callbacks])))->toThrow(LogicException::class, 'Streaming, cancellation and protected-input proofs require the native transport.');
})->with(['output', 'cancelled']);

it('leaves rejected protected input readable and caller owned', function (): void {
    $input = ProtectedInput::fromString("caller owned\0\n\n");
    try {
        expect(fn () => (new TurnMetadataSshExecutor)->execute(turn_metadata_fixture_connection(), new RemoteCommand(
            arguments: ['bash', '-seu'],
            protectedInput: $input,
        )))->toThrow(LogicException::class, 'Streaming, cancellation and protected-input proofs require the native transport.');
        expect(stream_get_contents($input->stream()))->toBe("caller owned\0\n\n");
    } finally {
        $input->close();
    }
});

it('rejects noncanonical base64 before decoding the metadata payload', function (): void {
    $command = turn_metadata_fixture_command('/unused', 'legacy');
    expect(fn () => (new TurnMetadataSshExecutor)->execute(turn_metadata_fixture_connection(), new RemoteCommand(
        arguments: $command->arguments,
        input: str_replace("'e30='", "'e31='", $command->input ?? ''),
    )))->toThrow(LogicException::class, 'The turn metadata fixture requires canonical base64 input.');
});

it('preserves isolation, arguments, current binary environment and deadlines without forwarding Composer credentials', function (): void {
    $root = sys_get_temp_dir().'/orbit-metadata-context-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);
    mkdir($root.'/resources', 0700);
    mkdir($root.'/resources/tasks', 0700);
    mkdir($root.'/checkout', 0700);
    mkdir($root.'/checkout/.git', 0700);
    $originalBase = base_path();
    $source = File::get(resource_path('tasks/metadata'));
    $probe = <<<'PYTHON'

        import signal
        print(json.dumps({'composer': os.environ.get('COMPOSER_AUTH'), 'value': base64.b64encode(os.environb[b'ORBIT_METADATA_VALUE']).decode(), 'isolated': sys.flags.isolated, 'ignore_environment': sys.flags.ignore_environment, 'arguments': sys.argv, 'cwd': os.getcwd()}), file=sys.stderr, flush=True)
        print('started', flush=True)
        if os.environ['ORBIT_METADATA_BLOCK'] == '1':
            signal.pause()
        PYTHON;
    File::put($root.'/resources/tasks/metadata', $source."\n".$probe);
    try {
        app()->setBasePath($root);
        $transport = new TurnMetadataSshExecutor;
        $value = "current environment\xff\n☄";
        $transport->environment = ['COMPOSER_AUTH' => 'must not reach the child', 'ORBIT_METADATA_VALUE' => $value, 'ORBIT_METADATA_BLOCK' => '0'];
        $command = turn_metadata_fixture_command($root.'/checkout', 'legacy');
        $first = $transport->execute(turn_metadata_fixture_connection(), $command);
        expect($first->exitCode)->toBe(0)->and($first->stdout)->toBe("missing\nstarted\n")
            ->and(json_decode($first->stderr, true, flags: JSON_THROW_ON_ERROR))->toBe([
                'composer' => null, 'value' => base64_encode($value), 'isolated' => 1, 'ignore_environment' => 1,
                'arguments' => ['-c', $root.'/checkout', 'legacy'], 'cwd' => getcwd(),
            ]);
        $transport->environment['ORBIT_METADATA_BLOCK'] = '1';
        $timedOut = $transport->execute(turn_metadata_fixture_connection(), new RemoteCommand(arguments: $command->arguments, input: $command->input, timeout: 0.05));
        expect($timedOut->exitCode)->toBe(124)->and($timedOut->stdout)->toBe("missing\nstarted\n")
            ->and($timedOut->stderr)->toContain('Python test program timed out.')
            ->and($timedOut->stderr)->not->toContain('must not reach the child');
        $transport->environment['ORBIT_METADATA_BLOCK'] = '0';
        $healthy = $transport->execute(turn_metadata_fixture_connection(), $command);
        expect($healthy->exitCode)->toBe(0)->and($healthy->stdout)->toBe("missing\nstarted\n");
    } finally {
        app()->setBasePath($originalBase);
        File::deleteDirectory($root);
    }
});
