<?php

declare(strict_types=1);

use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;

it('fingerprints only the exact public arguments and input for role images', function (): void {
    $command = new RemoteCommand(['bash', '-seu', '--', 'orbit'], "echo ready\n");

    expect($command->fingerprint())->toBe(hash('sha256', '{"arguments":["bash","-seu","--","orbit"],"input":"echo ready\\n"}'))
        ->and(new RemoteCommand($command->arguments, $command->input)->fingerprint())->toBe($command->fingerprint())
        ->and(new RemoteCommand(['bash', '-seu', '--', 'other'], $command->input)->fingerprint())->not->toBe($command->fingerprint())
        ->and(new RemoteCommand($command->arguments, "echo changed\n")->fingerprint())->not->toBe($command->fingerprint())
        ->and(new RemoteCommand($command->arguments, protectedInput: ProtectedInput::fromString('secret'))->fingerprint())->toBeNull();
});
