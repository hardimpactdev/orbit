<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\Certificates\GatewayLeafCertificateSigner;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;
use Tests\Support\TestOrbitHome;

/**
 * Run the production Rust OpenSSL argv with only the Node's domain and scratch paths substituted.
 * Rust tests also exercise request() itself; this boundary test couples its actual argv to the Gateway signer.
 */
function agentLeafRequest(string $directory, string $domain): string
{
    $source = file_get_contents(base_path('../agent/src/commands/dev_certificates.rs'));
    expect(preg_match('/fn request\(.*?(?=\n#\[derive)/s', $source, $body))->toBe(1);
    expect(preg_match('/let subject = (?:"([^"]*)"|format!\("([^"]*)", input.domain\));/', $body[0], $subject))->toBe(1);
    $values = [
        'key' => $directory.'/key.pem',
        'request' => $directory.'/request.pem',
        'subject' => ($subject[1] ?? '') !== '' ? $subject[1] : str_replace('{}', $domain, $subject[2]),
    ];
    if (preg_match('/let subject_alt_name = format!\("([^"]*)", input.domain\);/', $body[0], $san) === 1) {
        $values['subject_alt_name'] = str_replace('{}', $domain, $san[1]);
    }
    expect(preg_match_all('/"openssl",\s*&\[(.*?)\]/s', $body[0], $commands))->toBe(2);
    foreach ($commands[1] as $arguments) {
        $argv = ['openssl'];
        foreach (explode(',', $arguments) as $argument) {
            $argument = trim($argument);
            if ($argument === '') {
                continue;
            }
            $argv[] = str_starts_with($argument, '"')
                ? json_decode($argument, true, flags: JSON_THROW_ON_ERROR)
                : $values[ltrim($argument, '&')];
        }
        $result = (new NativeProcessRunner)->run(new ProcessInvocation($argv, timeout: 5));
        expect($result->exitCode)->toBe(0, $result->stderr);
    }

    return file_get_contents($directory.'/request.pem');
}

it('signs 253 character Instance addresses for hostname replacement and Project-root re-publication through the agent producer', function (): void {
    $directory = TestOrbitHome::scratch('agent-san');
    mkdir($directory.'/ca', 0700, true);
    config(['orbit.home' => $directory]);
    $key = file_get_contents(base_path('tests/Fixtures/gateway-bootstrap-root.key'));
    file_put_contents($directory.'/ca/root.key', $key);
    $configuration = $directory.'/root.cnf';
    file_put_contents($configuration, "[req]\ndistinguished_name=subject\n[subject]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n");
    $request = openssl_csr_new(['commonName' => 'Orbit Test Root'], $key, ['config' => $configuration]);
    $root = openssl_csr_sign($request, null, $key, 2, ['config' => $configuration, 'x509_extensions' => 'ca', 'digest_alg' => 'sha256']);
    openssl_x509_export($root, $rootCertificate);
    file_put_contents($directory.'/root.pem', $rootCertificate);
    $this->app->instance(GatewayLeafCertificateSigner::class, new GatewayLeafCertificateSigner(new NativeProcessRunner));

    $domain = str_repeat('n', 63).'.'.str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 61);
    expect(strlen($domain))->toBe(253);
    $node = new Node(['name' => 'app-dev', 'platform' => 'linux']);
    $node->id = 7;
    $instance = new Instance;
    $instance->id = 3;
    $instance->setRelation('node', $node);
    $route = new Route(['domain' => $domain]);
    $route->id = 5;
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldReceive('resolve')->with($node)->twice()->andReturn(new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    $signer = Mockery::mock(LeafCertificateSigner::class);
    $signer->shouldReceive('rootCertificate')->twice()->andReturn($rootCertificate);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $published = [];
    $agents->shouldReceive('run')->withArgs(function (Node $recipient, string $command, array $input) use ($node, $domain): bool {
        return $recipient === $node && $command === 'certificate.request-leaf' && $input['domain'] === $domain
            && $input['user'] === 'orbit' && $input['home'] === '/home/orbit';
    })->twice()->andReturnUsing(function (Node $node, string $command, array $input) use ($directory): array {
        return ['state' => 'request', 'request' => agentLeafRequest($directory, $input['domain'])];
    });
    $agents->shouldReceive('run')->withArgs(function (Node $recipient, string $command, array $input) use ($node, $domain, $directory, &$published): bool {
        if ($recipient !== $node || $command !== 'certificate.publish-leaf' || $input['domain'] !== $domain) {
            return false;
        }
        $certificate = $input['certificate'];
        $details = openssl_x509_parse($certificate);
        expect($details['subject']['CN'])->toBe('Orbit')
            ->and($details['extensions']['subjectAltName'])->toBe('DNS:'.$domain)
            ->and(openssl_pkey_get_details(openssl_pkey_get_public($certificate))['bits'])->toBe(2048)
            ->and(openssl_x509_check_private_key($certificate, file_get_contents($directory.'/key.pem')))->toBeTrue();
        file_put_contents($directory.'/leaf.pem', $certificate);
        $validation = (new NativeProcessRunner)->run(new ProcessInvocation([
            'openssl', 'verify', '-CAfile', $directory.'/root.pem', '-purpose', 'sslserver', '-verify_hostname', $domain, $directory.'/leaf.pem',
        ], timeout: 5));
        expect($validation->exitCode)->toBe(0, $validation->stderr);
        $published[] = $input['scope'];

        return true;
    })->twice()->andReturn([]);
    $manager = new RemoteAppDevCertificateManager($agents, $signer, $accounts);

    // NativeDevelopmentRouteProjector calls these for Cluster TLD replacement and root re-publication.
    $manager->convergeInstanceHostnameChange($instance, $domain);
    $manager->convergeInstance($instance, $route);

    expect($published)->toBe(['app-instance-3-hostname-change', 'app-instance-3']);
});
