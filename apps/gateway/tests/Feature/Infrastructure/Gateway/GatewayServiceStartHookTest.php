<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayServiceStartHook;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Illuminate\Support\Collection;

final readonly class NamedGatewayServiceStartHook implements GatewayServiceStartHook
{
    public function __construct(
        private string $name,
        private string $dropIn,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function dropIn(): string
    {
        return $this->dropIn;
    }

    public function description(): string
    {
        return 'test start hook';
    }

    public function renderHook(string $checkoutPath, string $orbitHome): string
    {
        return "#!/bin/bash\nexit 0\n";
    }
}

final class RecordingStartHookProcessRunner implements ProcessRunner
{
    /** @var list<ProcessInvocation> */
    public array $calls = [];

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->calls[] = $invocation;

        return new CommandResult(0, '', '', 2, false);
    }

    /** @return list<string> the hook installs and runs, and the pool reload, in order */
    public function steps(): array
    {
        $steps = [];
        foreach ($this->calls as $call) {
            if ($call->arguments === ['sudo', 'bash', '-seu'] && preg_match("/\\Aset -- '([^']+)'/", (string) $call->input, $match) === 1) {
                $steps[] = 'install '.$match[1];
            } elseif (count($call->arguments) === 2 && str_starts_with($call->arguments[1], '/etc/orbit/')) {
                $steps[] = 'run '.$call->arguments[1];
            } elseif ($call->arguments === ['sudo', 'systemctl', 'reload-or-restart', 'php8.5-fpm']) {
                $steps[] = 'reload';
            }
        }

        return $steps;
    }
}

it('installs every contributed hook, then runs each one before the pool reload', function (): void {
    $processes = new RecordingStartHookProcessRunner;

    new NativeGatewayFpmConverger($processes, [
        new NamedGatewayServiceStartHook('first-start', 'orbit-first.conf'),
        new NamedGatewayServiceStartHook('second-start', 'orbit-second.conf'),
    ])->converge('/tmp/orbit-gateway-pool.conf');

    $dropIn = escapeshellarg("# Managed by Orbit: test start hook\n[Service]\nExecStartPre=+/etc/orbit/first-start\n");
    expect($processes->steps())->toBe([
        'install first-start',
        'install second-start',
        'run /etc/orbit/first-start',
        'run /etc/orbit/second-start',
        'reload',
    ])->and(Collection::make($processes->calls)->contains(static fn (ProcessInvocation $call): bool => str_contains((string) $call->input, $dropIn)))->toBeTrue();
});

it('still reloads the pool without a contributed hook', function (): void {
    $processes = new RecordingStartHookProcessRunner;

    new NativeGatewayFpmConverger($processes, [])->converge('/tmp/orbit-gateway-pool.conf');

    expect($processes->steps())->toBe(['reload']);
});

it('refuses a hook file name that leaves its directory before it installs a hook or reloads', function (string $name, string $dropIn): void {
    $processes = new RecordingStartHookProcessRunner;
    $converger = new NativeGatewayFpmConverger($processes, [new NamedGatewayServiceStartHook($name, $dropIn)]);

    expect(fn () => $converger->converge('/tmp/orbit-gateway-pool.conf'))->toThrow(LogicException::class)
        ->and($processes->steps())->toBe([]);
})->with([
    'parent program' => ['../start', 'orbit-test.conf'],
    'nested drop-in' => ['test-start', 'nested/orbit-test.conf'],
    'hidden drop-in' => ['test-start', '.orbit-test.conf'],
    'empty program' => ['', 'orbit-test.conf'],
]);
