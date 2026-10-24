<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeEnrollmentPrograms;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Illuminate\Support\Facades\Artisan;

/**
 * @param  list<string>  $roles
 * @return list<array{name: string, arguments: list<string>, input: string|null, fingerprint: string}>
 */
function render_node_programs(array $roles): array
{
    expect(Artisan::call('orbit:node-programs', ['roles' => $roles, '--json' => true]))->toBe(0);

    $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    $node = new Node(['platform' => 'linux', 'architecture' => 'x86_64']);
    expect(array_keys($output))->toBe(['agent', 'programs'])
        ->and($output['agent'])->toBe(['path' => app(AgentDelivery::class)->localFile($node), 'sha256' => app(AgentDelivery::class)->pin($node)]);

    return $output['programs'];
}

/** @param list<array{name: string}> $programs */
function node_program_names(array $programs): array
{
    return array_column($programs, 'name');
}

describe('orbit:node-programs', function (): void {
    beforeEach(function (): void {
        app()->instance(SshKeyProvider::class, new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/keys/id_ed25519';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGatewayTestKey gateway';
            }
        });
    });

    it('renders the enrollment programs for app-dev', function (): void {
        $recipe = app(NodeEnrollmentPrograms::class)->render([RoleName::AppDev]);
        $expected = [];
        foreach ($recipe as $name => $program) {
            $expected[] = ['name' => $name, 'arguments' => $program->arguments, 'input' => $program->input, 'fingerprint' => $program->fingerprint()];
        }

        $programs = render_node_programs(['app-dev']);

        expect($programs)->toBe($expected)
            ->and(array_slice(node_program_names($programs), 0, 5))->toBe(['bootstrap', 'caddy-package', 'app-dev-prerequisites', 'vp-manager', 'composer-manager'])
            ->and(node_program_names($programs)[5])->toBe('app-dev-php-install')
            ->and(array_keys($programs[0]))->toBe(['name', 'arguments', 'input', 'fingerprint'])
            ->and(array_column($programs, 'fingerprint'))->each->toMatch('/\A[a-f0-9]{64}\z/');
        expect($programs[4]['arguments'])->toBe(['sudo', '-n', '/usr/local/bin/orbit-agent', 'command', 'tools.materialize-composer']);
        expect(json_decode($programs[4]['input'], true, flags: JSON_THROW_ON_ERROR)['input'])->toBe(['user' => 'orbit']);
        expect($programs[3]['arguments'])->toBe(['sudo', '-n', '/usr/local/bin/orbit-agent', 'command', 'tools.materialize-vp']);
        $input = json_decode($programs[3]['input'], true, flags: JSON_THROW_ON_ERROR)['input'];
        expect($input)->toMatchArray(['user' => 'orbit', 'architecture' => 'amd64']);
        expect(hash('sha256', $input['vp_lock']))->toBe($input['tools']['vp']['lock_sha256']);
    });

    it('renders the app-prod programs', function (): void {
        $programs = render_node_programs(['app-prod']);

        expect(node_program_names($programs))->toBe(['bootstrap', 'caddy-package', 'app-prod-prerequisites', 'vp-manager', 'composer-manager'])
            ->and(json_decode((string) $programs[2]['input'], true)['input'])->toMatchArray(['user' => 'orbit', 'home' => '/home/orbit', 'application_runtime' => true, 'apps_directory' => false])
            ->and(json_decode((string) $programs[2]['input'], true)['input']['packages'])->toContain('docker.io', 'caddy');
    });

    it('renders ported programs as calls to the pinned agent', function (): void {
        $programs = array_column(render_node_programs(['vpn']), null, 'name');
        $node = new Node(['platform' => 'linux', 'architecture' => 'x86_64']);

        expect($programs['vpn-prerequisites']['arguments'])->toBe(['sudo', '-n', '/usr/local/bin/orbit-agent', 'command', 'roles.install-prerequisites'])
            ->and(json_decode((string) $programs['vpn-prerequisites']['input'], true)['agent_sha256'])->toBe(app(AgentDelivery::class)->pin($node));
    });

    it('renders Caddy once and each role prerequisite in order for gateway and vpn', function (): void {
        $programs = render_node_programs(['gateway', 'vpn']);

        expect(node_program_names($programs))->toBe(['bootstrap', 'caddy-package', 'vpn-prerequisites'])
            ->and(json_decode((string) $programs[2]['input'], true)['input']['packages'])->toBe(['dnsmasq', 'openssl']);
    });

    it('renders shared programs once, at the first role that runs them', function (): void {
        $names = node_program_names(render_node_programs(['router', 'app-prod', 'app-dev']));

        expect(array_slice($names, 0, 7))->toBe([
            'bootstrap', 'caddy-package', 'router-prerequisites', 'app-prod-prerequisites', 'vp-manager', 'composer-manager', 'app-dev-prerequisites',
        ])->and(array_count_values($names))->each->toBe(1);
    });

    it('renders only the roleless operator bootstrap without roles', function (): void {
        $programs = render_node_programs([]);
        expect($programs)->toHaveCount(1)
            ->and($programs[0]['name'])->toBe('bootstrap')
            ->and($programs[0]['arguments'])->toBe(['sudo', '-n', '/usr/local/bin/orbit-agent', 'command', 'nodes.bootstrap'])
            ->and(json_decode($programs[0]['input'], true, flags: JSON_THROW_ON_ERROR)['input']['dns_policy'])->toBe('preserve');
    });

    it('prints one program name per line without --json', function (): void {
        expect(Artisan::call('orbit:node-programs', ['roles' => ['vpn']]))->toBe(0)
            ->and(Artisan::output())->toBe("bootstrap\nvpn-prerequisites\n");
    });

    it('fails for a role it cannot render', function (array $roles, string $message): void {
        expect(Artisan::call('orbit:node-programs', ['roles' => $roles, '--json' => true]))->toBe(1)
            ->and(Artisan::output())->toContain($message)->not->toContain('"programs"');
    })->with([
        'unknown role' => [['app-dev', 'mainframe'], 'Unknown role [mainframe].'],
        'metrics' => [['metrics'], 'Role [metrics] has no enrollment programs'],
        'analytics' => [['analytics'], 'Role [analytics] has no enrollment programs'],
        'repeated role' => [['vpn', 'vpn'], 'Role [vpn] is repeated.'],
    ]);
});
