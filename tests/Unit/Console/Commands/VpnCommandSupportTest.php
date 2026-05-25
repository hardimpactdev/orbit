<?php

declare(strict_types=1);

use App\Console\Commands\VpnCommandSupport;
use App\Services\RemoteShell\RemoteOrbitRuntimeExecutor;
use Tests\TestCase;

uses(TestCase::class);

it('builds vpn forwarding scripts for the runtime executor without host php artisan', function (): void {
    $command = new VpnCommandSupportGatewayScriptCommand(app(RemoteOrbitRuntimeExecutor::class));

    $script = $command->exposeGatewayScript('vpn-client:list', [], [
        'totp' => '123456',
        'config' => false,
    ]);

    expect(str_starts_with($script, 'artisan vpn-client:list'))->toBeTrue();
    expect($script)->toContain('--totp=');
    expect($script)->toContain('123456');
    expect($script)->toContain('--json');
    expect($script)->not->toMatch('/\bphp\s+artisan\b/');
});

final class VpnCommandSupportGatewayScriptCommand extends VpnCommandSupport
{
    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<string, mixed>  $options
     */
    public function exposeGatewayScript(string $command, array $arguments, array $options): string
    {
        return $this->gatewayScript($command, $arguments, $options);
    }

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
