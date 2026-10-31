<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Swamp\SwampVaultExecutionCopy;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config(['swamp.run_token' => 'synthetic-gateway.private', 'swamp.ca_path' => '/public/root.pem']);
    $this->runner = new class implements ProcessRunner
    {
        public array $calls = [];

        public array $values = [];

        public bool $fail = false;

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->calls[] = $invocation;
            $this->values[] = stream_get_contents($invocation->protectedInput->stream());
            if ($this->fail) {
                throw new RuntimeException($this->values[array_key_last($this->values)]);
            }

            return new CommandResult(0, '{}', '', 1, false);
        }
    };
    $this->copies = new SwampVaultExecutionCopy($this->runner);
});

it('pushes changed source values through native protected stdin and returns only vault key names', function (): void {
    $key = 'bootstrap-identity-node-'.fake()->numberBetween(100000, 999999);
    $value = "-----BEGIN OPENSSH PRIVATE KEY-----\n".bin2hex(random_bytes(32))."\n-----END OPENSSH PRIVATE KEY-----\n";
    $reference = $this->copies->synchronize('orbit-control-inputs', $key, $value);
    expect($reference)->toBe(['vault' => 'orbit-control-inputs', 'key' => $key]);
    expect($this->runner->values)->toBe([$value]);
    $call = $this->runner->calls[0];
    expect($call->arguments)->toBe(['/usr/local/bin/swamp', 'vault', 'put', 'orbit-control-inputs', $key,
        '--server', 'wss://swamp.orbit', '--ca-cert', '/public/root.pem', '--force', '--json']);
    expect(json_encode($call->arguments))->not->toContain($value)->not->toContain('synthetic-gateway.private');
    expect($call->input)->toBeNull();
    expect($call->protectedInput->__debugInfo())->toBe(['input' => '[PROTECTED]']);
    $this->copies->synchronize('orbit-control-inputs', $key, $value);
    expect($this->runner->calls)->toHaveCount(1);
    $this->copies->synchronize('orbit-control-inputs', $key, $value.'changed');
    expect($this->runner->calls)->toHaveCount(2);
    expect(Cache::get('swamp:execution-copy:'.hash('sha256', "wss://swamp.orbit\0orbit-control-inputs\0".$key)))
        ->toBe(hash('sha256', $value.'changed'));
});

it('does not mark a refused copy synchronized and drops secret-bearing process diagnostics', function (): void {
    $key = 'bootstrap-identity-node-'.fake()->numberBetween(100000, 999999);
    $value = 'synthetic-private-'.bin2hex(random_bytes(32));
    $this->runner->fail = true;
    try {
        $this->copies->synchronize('orbit-control-inputs', $key, $value);
        test()->fail('A failed native write must refuse.');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('swamp.vault_sync_unavailable');
        expect((string) $error)->not->toContain($value);
        expect($error->getPrevious())->toBeNull();
    }
    $this->runner->fail = false;
    $this->copies->synchronize('orbit-control-inputs', $key, $value);
    expect($this->runner->calls)->toHaveCount(2);
});

it('refuses invalid names or credential-bearing endpoints before opening a process', function (string $vault, string $key, string $server): void {
    config(['swamp.vault_server' => $server]);
    expect(fn () => $this->copies->synchronize($vault, $key, 'synthetic-value'))->toThrow(ResourceOperationException::class);
    expect($this->runner->calls)->toBeEmpty();
})->with([
    ['--vault', 'key', 'wss://swamp.orbit'],
    ['orbit-control-inputs', '--key', 'wss://swamp.orbit'],
    ['orbit-control-inputs', 'key', 'ws://swamp.orbit'],
    ['orbit-control-inputs', 'key', 'wss://user:password@swamp.orbit'],
]);
