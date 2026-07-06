<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Gateway\GatewayHostAgentConverger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Process::preventStrayProcesses();
    $this->configRoot = sys_get_temp_dir().'/orbit-gateway-host-agent-'.Str::random(8);
    config()->set('orbit.paths.config_root', $this->configRoot);
    config()->set('orbit.agent.binary', '/usr/local/bin/orbit-agent');
});

afterEach(function (): void {
    if (isset($this->configRoot) && is_dir($this->configRoot)) {
        File::deleteDirectory($this->configRoot);
    }
});

it('writes gateway host agent config and converges the systemd service', function (): void {
    $systemdUnit = null;
    $gateway = Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'user' => 'orbit',
            'wireguard_address' => '10.6.0.2',
            'orbit_agent_capable' => false,
        ]);
    File::ensureDirectoryExists("{$this->configRoot}/ca");
    File::put("{$this->configRoot}/ca/root.crt", 'root-ca');

    Process::fake(function ($process) use (&$systemdUnit) {
        if ($process->command === 'sudo tee /etc/systemd/system/orbit-agent.service > /dev/null') {
            $systemdUnit = $process->input;
        }

        return Process::result();
    });

    $path = app(GatewayHostAgentConverger::class)->converge($gateway);

    expect($path)
        ->toBe("{$this->configRoot}/agent.toml")
        ->and(File::get($path))
        ->toContain('gateway_url = "https://10.6.0.2"')
        ->toContain('node_name = "gateway-1"')
        ->toContain('ca_pem_path = "'.$this->configRoot.'/ca/root.crt"')
        ->not
        ->toContain('bearer_token')
        ->and($systemdUnit)
        ->toContain('User=orbit')
        ->toContain('Environment=ORBIT_AGENT_CONFIG='.$path)
        ->toContain('ExecStart=/usr/local/bin/orbit-agent')
        ->and($gateway->fresh()->orbit_agent_capable)
        ->toBeTrue();

    expect(substr(sprintf('%o', fileperms($this->configRoot)), -4))
        ->toBe('0711')
        ->and(substr(sprintf('%o', fileperms($path)), -4))
        ->toBe('0644')
        ->and(substr(sprintf('%o', fileperms("{$this->configRoot}/ca")), -4))
        ->toBe('0711')
        ->and(substr(sprintf('%o', fileperms("{$this->configRoot}/ca/root.crt")), -4))
        ->toBe('0644');

    Process::assertRan('test -x \'/usr/local/bin/orbit-agent\'');
    Process::assertRan('sudo systemctl daemon-reload');
    Process::assertRan('sudo systemctl enable orbit-agent');
    Process::assertRan('sudo systemctl restart orbit-agent');
});
