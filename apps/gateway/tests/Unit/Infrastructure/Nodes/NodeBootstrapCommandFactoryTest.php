<?php

declare(strict_types=1);

use App\Infrastructure\Nodes\NodeBootstrapCommandFactory;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

it('allows the managed Node user to sudo as production Instance users', function (): void {
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->once()->andReturn('ssh-ed25519 test-key');
    $command = new NodeBootstrapCommandFactory($keys)->make(new Node, 'orbit');

    expect($command->input)
        ->toContain("printf '%s ALL=(ALL) NOPASSWD:ALL\\n' \"\$managed_user\"")
        ->toContain('visudo -cf "$sudoers"')
        ->toContain('install -m 0440 -o root -g root "$sudoers" "/etc/sudoers.d/$managed_user"');
});
