<?php

declare(strict_types=1);

use App\Domain\Routes\RouteStateResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Certificates\GatewayLeafCertificateSigner;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\TestOrbitHome;

beforeEach(function (): void {
    $this->directory = TestOrbitHome::scratch('san-leaf');
    mkdir($this->directory.'/ca', 0700, true);
    config(['orbit.home' => $this->directory]);
    $key = file_get_contents(base_path('tests/Fixtures/gateway-bootstrap-root.key'));
    file_put_contents($this->directory.'/ca/root.key', $key);
    $configuration = $this->directory.'/root.cnf';
    file_put_contents($configuration, "[req]\ndistinguished_name=subject\n[subject]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n");
    $request = openssl_csr_new(['commonName' => 'Orbit Test Root'], $key, ['config' => $configuration]);
    $root = openssl_csr_sign($request, null, $key, 2, ['config' => $configuration, 'x509_extensions' => 'ca', 'digest_alg' => 'sha256']);
    openssl_x509_export($root, $this->root);
    file_put_contents($this->directory.'/root.pem', $this->root);
    $this->processes = new NativeProcessRunner;
    $this->signer = new GatewayLeafCertificateSigner($this->processes);
});

/** Execute the production CSR argv, substituting only public inputs and the key's scratch path. */
function routeSanRequest(string $workflow, string $directory, string $domain): ProcessInvocation
{
    $definition = Yaml::parseFile(base_path('../../swamp/workflows/'.$workflow.'.yaml'));
    $steps = $definition['jobs'][0]['steps'];
    $step = array_values(array_filter($steps, static fn (array $step): bool => in_array($step['name'], ['csr', 'Make a CSR on the Node'], true)))[0];
    $expression = substr($step['task']['inputs']['argv'], 4, -3);
    $expression = strtr($expression, [
        '"/etc/caddy/orbit-certificates/" + self.leaf.scope + "/" + "versions/" + self.leaf.version + "/key.pem"' => json_encode($directory.'/key.pem'),
        '"/var/lib/orbit/role-certificates/" + inputs.leaf.scope + "/" + inputs.leaf.version + "/key.pem"' => json_encode($directory.'/key.pem'),
        'inputs.intent.leaves[inputs.leaf].directory' => json_encode($directory),
        'inputs.intent.leaves[inputs.leaf].domain' => json_encode($domain),
        '"subjectAltName=DNS:" + self.leaf.domain' => json_encode('subjectAltName=DNS:'.$domain),
        '"subjectAltName=DNS:" + inputs.leaf.domain' => json_encode('subjectAltName=DNS:'.$domain),
        '"/CN=" + self.leaf.domain' => json_encode('/CN='.$domain),
        '"/CN=" + inputs.leaf.domain' => json_encode('/CN='.$domain),
    ]);
    $argv = json_decode($expression, true, flags: JSON_THROW_ON_ERROR);
    if ($workflow === 'workflow-route-create-csr') {
        // The Node's surrounding install/chown needs root:caddy; run its unchanged CSR statement here.
        expect(preg_match('/openssl req .+?(?= &&)/', $argv[5], $match))->toBe(1);
        $argv[5] = $match[0];
    }

    return new ProcessInvocation($argv, timeout: 5);
}

function expectRouteSanLeaf(string $domain, string $request): string
{
    $certificate = test()->signer->sign($domain, $request, test()->root);
    $path = test()->directory.'/leaf.pem';
    file_put_contents($path, $certificate);
    $details = openssl_x509_parse($certificate);
    expect($details['extensions']['subjectAltName'])->toBe('DNS:'.$domain)
        ->and($details['extensions']['basicConstraints'])->toBe('CA:FALSE')
        ->and($details['extensions']['extendedKeyUsage'])->toBe('TLS Web Server Authentication')
        ->and(openssl_x509_check_private_key($certificate, file_get_contents(test()->directory.'/key.pem')))->toBeTrue();
    $validation = test()->processes->run(new ProcessInvocation([
        'openssl', 'verify', '-CAfile', test()->directory.'/root.pem', '-purpose', 'sslserver', '-verify_hostname', $domain, $path,
    ], timeout: 5));
    expect($validation->exitCode)->toBe(0, $validation->stderr);

    return $certificate;
}

describe('SAN leaf issuance', function (): void {
    it('makes and signs a real CSR for a 63 character Instance name at the 253 character domain limit', function (string $workflow): void {
        $name = str_repeat('n', 63);
        $tld = str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 56);
        $domain = app(RouteStateResolver::class)->generatedDomain('tool', $name, $tld);
        expect(strlen($domain))->toBe(253);

        $result = $this->processes->run(routeSanRequest($workflow, $this->directory, $domain));

        expect($result->exitCode)->toBe(0, $result->stderr)
            ->and(openssl_csr_get_subject($result->stdout)['CN'])->toBe('Orbit');
        $certificate = expectRouteSanLeaf($domain, $result->stdout);
        expect(openssl_x509_parse($certificate)['subject']['CN'])->toBe('Orbit');
    })->with(['workflow-route-create-csr', 'workflow-route-certificate-publish', 'workflow-node-role-certificate-publish']);

    it('still signs and validates an old Node request during release overlap', function (bool $withSan): void {
        $domain = 'existing.tool.test';
        $argv = ['openssl', 'req', '-quiet', '-new', '-newkey', 'rsa:2048', '-nodes', '-keyout', $this->directory.'/key.pem', '-subj', '/CN='.$domain];
        if ($withSan) {
            $argv = [...$argv, '-addext', 'subjectAltName=DNS:'.$domain];
        }
        $result = $this->processes->run(new ProcessInvocation($argv, timeout: 5));
        expect($result->exitCode)->toBe(0);
        $certificate = expectRouteSanLeaf($domain, $result->stdout);
        expect(openssl_x509_parse($certificate)['subject']['CN'])->toBe($domain);
    })->with(['previous workflow without SAN' => false, 'old CN with exact SAN' => true]);

    it('refuses a new request whose SAN is missing or is not the admitted domain alone', function (?string $san): void {
        $argv = ['openssl', 'req', '-quiet', '-new', '-newkey', 'rsa:2048', '-nodes', '-keyout', $this->directory.'/key.pem', '-subj', '/CN=Orbit'];
        if ($san !== null) {
            $argv = [...$argv, '-addext', 'subjectAltName='.$san];
        }
        $result = $this->processes->run(new ProcessInvocation($argv, timeout: 5));
        expect($result->exitCode)->toBe(0)
            ->and(fn () => $this->signer->sign('tool.test', $result->stdout, $this->root))
            ->toThrow(ResourceOperationException::class, 'The CSR must be an RSA 2048 request for the admitted domain only.');
    })->with(['missing SAN' => null, 'different DNS name' => 'DNS:other.test', 'extra DNS name' => 'DNS:tool.test,DNS:other.test']);

    it('keeps a real CSR failure diagnostic visible without RSA progress dots', function (string $workflow): void {
        $result = $this->processes->run(routeSanRequest($workflow, $this->directory.'/missing', 'tool.test'));

        expect($result->exitCode)->not->toBe(0)
            ->and($result->stderr)->toContain('No such file or directory')->not->toMatch('/[.+*]{20}/');
    })->with(['workflow-route-create-csr', 'workflow-route-certificate-publish', 'workflow-node-role-certificate-publish']);
});
