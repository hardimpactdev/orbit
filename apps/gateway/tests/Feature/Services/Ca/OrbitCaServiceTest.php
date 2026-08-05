<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Ca\OrbitCaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

function orbitCaServiceTestSeedRootFiles(
    string $rootCrt = "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n",
    string $rootKey = 'test-root-key',
): void {
    $caDir = orbitCaServiceTestCaDir();

    File::ensureDirectoryExists($caDir);
    File::put("{$caDir}/root.crt", $rootCrt);
    File::put("{$caDir}/root.key", $rootKey);
    chmod("{$caDir}/root.key", 0600);
}

function orbitCaServiceTestConfigRoot(): string
{
    $configRoot = config('orbit.paths.config_root');

    expect($configRoot)->toBeString();

    return rtrim((string) $configRoot, '/');
}

function orbitCaServiceTestCaDir(): string
{
    return orbitCaServiceTestConfigRoot().'/ca';
}

function orbitCaServiceTestSeedValidRootFixture(): void
{
    static $fixture = null;

    if ($fixture === null) {
        $fixtureDir = sys_get_temp_dir().'/orbit-ca-service-fixture-'.getmypid();
        File::ensureDirectoryExists($fixtureDir);

        $rootKey = "{$fixtureDir}/root.key";
        $rootCrt = "{$fixtureDir}/root.crt";

        if (! File::exists($rootKey) || ! File::exists($rootCrt)) {
            $factory = new Factory;
            $factory->run(sprintf('openssl genrsa -out %s 2048', escapeshellarg($rootKey)))->throw();
            $factory->run(implode(' ', [
                'openssl req -x509 -new -nodes',
                '-key '.escapeshellarg($rootKey),
                '-sha256 -days 3650',
                '-out '.escapeshellarg($rootCrt),
                '-subj '.escapeshellarg('/CN=Orbit Test Root CA/O=Orbit Tests'),
            ]))->throw();
        }

        $fixture = [
            'crt' => File::get($rootCrt),
            'key' => File::get($rootKey),
        ];
    }

    orbitCaServiceTestSeedRootFiles($fixture['crt'], $fixture['key']);
}

function orbitCaServiceTestCreateGatewayNode(): Node
{
    return Node::factory()
        ->gateway()
        ->create([
            'name' => 'test-gateway',
            'status' => 'active',
            'host' => '10.6.0.1',
            'orbit_path' => '/home/orbit/orbit',
        ]);
}

function orbitCaServiceTestSignLeafForDays(string $host, string $keyPath, string $certPath, int $days): void
{
    $tmp = tempnam(sys_get_temp_dir(), 'orbit-ca-short-leaf-');
    expect($tmp)->toBeString();

    $csrPath = "{$tmp}.csr";
    $extPath = "{$tmp}.ext";
    $caDir = orbitCaServiceTestCaDir();
    $factory = new Factory;

    try {
        $factory->run(sprintf(
            'openssl req -new -key %s -out %s -subj %s',
            escapeshellarg($keyPath),
            escapeshellarg($csrPath),
            escapeshellarg("/CN={$host}"),
        ))->throw();

        File::put($extPath, implode("\n", [
            "subjectAltName=DNS:{$host}",
            'keyUsage=digitalSignature,keyEncipherment',
            'extendedKeyUsage=serverAuth',
        ]));

        $factory->run(implode(' ', [
            'openssl x509 -req',
            '-in '.escapeshellarg($csrPath),
            '-CA '.escapeshellarg("{$caDir}/root.crt"),
            '-CAkey '.escapeshellarg("{$caDir}/root.key"),
            '-set_serial '.escapeshellarg('0x'.bin2hex(random_bytes(16))),
            '-out '.escapeshellarg($certPath),
            '-days '.$days,
            '-sha256',
            '-extfile '.escapeshellarg($extPath),
        ]))->throw();
    } finally {
        File::delete([$csrPath, $extPath, $tmp]);
    }
}

function orbitCaServiceTestCertSerial(string $certPath): string
{
    return trim(
        new Factory()->run(sprintf(
            'openssl x509 -in %s -serial -noout',
            escapeshellarg($certPath),
        ))->output(),
    );
}

function orbitCaServiceTestCertValidityDays(string $certPath): int
{
    $dates = new Factory()->run(sprintf(
        'openssl x509 -in %s -noout -startdate -enddate',
        escapeshellarg($certPath),
    ))->output();

    preg_match('/notBefore=(.+)/', $dates, $notBefore);
    preg_match('/notAfter=(.+)/', $dates, $notAfter);

    expect($notBefore[1] ?? null)->toBeString();
    expect($notAfter[1] ?? null)->toBeString();

    $startsAt = new DateTimeImmutable($notBefore[1]);
    $expiresAt = new DateTimeImmutable($notAfter[1]);
    $days = $startsAt->diff($expiresAt)->days;

    expect($days)->toBeInt();

    return (int) $days;
}

describe('OrbitCaService', function () {
    beforeEach(function () {
        $this->tempStorage = sys_get_temp_dir().'/orbit-ca-test-'.uniqid();
        app()->useStoragePath($this->tempStorage);
        $this->tempConfigRoot = "{$this->tempStorage}/config";
        File::ensureDirectoryExists($this->tempConfigRoot);
        config(['orbit.paths.config_root' => $this->tempConfigRoot]);
        Process::swap(new Factory);
    });

    afterEach(function () {
        if (isset($this->tempStorage) && is_dir($this->tempStorage)) {
            File::deleteDirectory($this->tempStorage);
        }
    });

    describe('ensureRootCa()', function () {
        it('generates root.crt and root.key on a local gateway node', function () {
            orbitCaServiceTestCreateGatewayNode();

            $service = new OrbitCaService;
            $caDir = orbitCaServiceTestCaDir();

            orbitCaServiceTestSeedRootFiles();

            $service->ensureRootCa();

            expect(File::exists("{$caDir}/root.crt"))->toBeTrue();
            expect(File::exists("{$caDir}/root.key"))->toBeTrue();
            expect(decoct(fileperms("{$caDir}/root.key") & 0777))->toBe('600');
        });

        it('is idempotent: running twice leaves files unchanged', function () {
            orbitCaServiceTestCreateGatewayNode();

            $service = new OrbitCaService;
            $caDir = orbitCaServiceTestCaDir();

            $generationAttempt = 0;
            $commands = [];

            Process::fake(function ($process) use ($caDir, &$generationAttempt, &$commands) {
                $command = (string) $process->command;
                $commands[] = $command;

                if (str_contains($command, 'openssl genrsa')) {
                    $generationAttempt++;

                    File::put("{$caDir}/root.key", "generated-root-key-{$generationAttempt}");

                    return Process::result();
                }

                if (str_contains($command, 'openssl req -x509')) {
                    File::put(
                        "{$caDir}/root.crt",
                        "-----BEGIN CERTIFICATE-----\ngenerated-root-cert-{$generationAttempt}\n-----END CERTIFICATE-----\n",
                    );

                    return Process::result();
                }

                return Process::result();
            });
            Process::preventStrayProcesses();

            $service->ensureRootCa();

            $crtBefore = File::get("{$caDir}/root.crt");
            $keyBefore = File::get("{$caDir}/root.key");

            $service->ensureRootCa();

            expect($commands)
                ->toHaveCount(2)
                ->and($commands[0])
                ->toContain('openssl genrsa')
                ->toContain('4096')
                ->toContain(escapeshellarg("{$caDir}/root.key"))
                ->and($commands[1])
                ->toContain('openssl req -x509 -new -nodes')
                ->toContain('-sha256 -days 3650')
                ->toContain(escapeshellarg("{$caDir}/root.key"))
                ->toContain(escapeshellarg("{$caDir}/root.crt"))
                ->toContain(escapeshellarg('/CN=Orbit Root CA/O=Orbit'));

            expect(File::get("{$caDir}/root.crt"))->toBe($crtBefore);
            expect(File::get("{$caDir}/root.key"))->toBe($keyBefore);
        });

        it('throws with "restore" message when only root.crt exists', function () {
            orbitCaServiceTestCreateGatewayNode();

            $service = new OrbitCaService;
            $caDir = orbitCaServiceTestCaDir();

            File::ensureDirectoryExists($caDir);
            File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");
            $crtContent = File::get("{$caDir}/root.crt");

            expect(fn () => $service->ensureRootCa())
                ->toThrow(RuntimeException::class, 'restore');

            expect(File::get("{$caDir}/root.crt"))->toBe($crtContent);
        });

        it('throws mentioning "gateway" when no local gateway node exists', function () {
            Node::create([
                'name' => 'not-a-gateway',
                'tld' => 'not-a-gateway',
                'status' => 'active',
                'host' => '127.0.0.1',
                'orbit_path' => base_path(),
            ]);

            $service = new OrbitCaService;

            expect(fn () => $service->ensureRootCa())
                ->toThrow(RuntimeException::class, 'gateway');
        });
    });

    describe('issueLeaf()', function () {
        beforeEach(function () {
            orbitCaServiceTestCreateGatewayNode();

            orbitCaServiceTestSeedValidRootFixture();
        });

        it('issues a runtime-private leaf cert for a DNS host and returns correct paths', function () {
            $service = new OrbitCaService;
            $dataPath = orbitCaServiceTestConfigRoot();

            $paths = $service->issueLeaf('demo.beast');

            expect($paths['cert'])->toBe("{$dataPath}/certs/demo.beast.crt");
            expect($paths['key'])->toBe("{$dataPath}/certs/demo.beast.key");
            expect(File::exists($paths['cert']))->toBeTrue();
            expect(File::exists($paths['key']))->toBeTrue();
            expect(decoct(fileperms($paths['key']) & 0777))->toBe('600');

            $caDir = "{$dataPath}/ca";
            $verify = new Factory()->run(
                sprintf(
                    'openssl verify -CAfile %s %s',
                    escapeshellarg("{$caDir}/root.crt"),
                    escapeshellarg($paths['cert']),
                ),
            );
            expect($verify->successful())->toBeTrue();
        });

        it('is idempotent: calling twice within freshness window returns same serial', function () {
            $service = new OrbitCaService;
            $dataPath = orbitCaServiceTestConfigRoot();

            $paths1 = $service->issueLeaf('demo.beast');
            $paths2 = $service->issueLeaf('demo.beast');

            $factory = new Factory;

            $serial1 = $factory->run(sprintf(
                'openssl x509 -in %s -serial -noout',
                escapeshellarg($paths1['cert']),
            ))->output();
            $serial2 = $factory->run(sprintf(
                'openssl x509 -in %s -serial -noout',
                escapeshellarg($paths2['cert']),
            ))->output();

            expect(trim($serial1))->toBe(trim($serial2));
        });

        it('embeds IP SAN for an IP host', function () {
            $service = new OrbitCaService;
            $paths = $service->issueLeaf('10.0.0.1');

            $factory = new Factory;
            $text = $factory->run(sprintf(
                'openssl x509 -in %s -text -noout',
                escapeshellarg($paths['cert']),
            ))->output();

            expect($text)->toContain('IP Address:10.0.0.1');
        });

        it('embeds DNS SAN for a DNS host', function () {
            $service = new OrbitCaService;
            $paths = $service->issueLeaf('demo.beast');

            $factory = new Factory;
            $text = $factory->run(sprintf(
                'openssl x509 -in %s -text -noout',
                escapeshellarg($paths['cert']),
            ))->output();

            expect($text)->toContain('DNS:demo.beast');
        });

        it('issues leaf certificates with the Orbit default 397-day validity', function () {
            $service = new OrbitCaService;
            $paths = $service->issueLeaf('demo.beast');

            expect(orbitCaServiceTestCertValidityDays($paths['cert']))->toBeIn([396, 397]);
        });

        it('reissues fresh leaves whose validity is shorter than the Orbit default', function () {
            $service = new OrbitCaService;
            $paths = $service->issueLeaf('demo.beast');

            orbitCaServiceTestSignLeafForDays(
                host: 'demo.beast',
                keyPath: $paths['key'],
                certPath: $paths['cert'],
                days: 90,
            );

            $shortSerial = orbitCaServiceTestCertSerial($paths['cert']);

            expect(orbitCaServiceTestCertValidityDays($paths['cert']))->toBeLessThan(396);

            $renewed = $service->issueLeaf('demo.beast');

            expect(orbitCaServiceTestCertSerial($renewed['cert']))
                ->not
                ->toBe($shortSerial)
                ->and(orbitCaServiceTestCertValidityDays($renewed['cert']))
                ->toBeIn([396, 397]);
        });

        it('reissues fresh overlong leaves whose validity exceeds the Orbit default', function () {
            $service = new OrbitCaService;
            $paths = $service->issueLeaf('demo.beast');

            orbitCaServiceTestSignLeafForDays(
                host: 'demo.beast',
                keyPath: $paths['key'],
                certPath: $paths['cert'],
                days: 3650,
            );

            $overlongSerial = orbitCaServiceTestCertSerial($paths['cert']);

            expect(orbitCaServiceTestCertValidityDays($paths['cert']))->toBeGreaterThan(397);

            $renewed = $service->issueLeaf('demo.beast');

            expect(orbitCaServiceTestCertSerial($renewed['cert']))
                ->not
                ->toBe($overlongSerial)
                ->and(orbitCaServiceTestCertValidityDays($renewed['cert']))
                ->toBeIn([396, 397]);
        });

        it('embeds additional SANs when issuing a DNS host leaf', function () {
            $service = new OrbitCaService;
            $paths = $service->issueLeaf('gateway', ['10.6.0.2']);

            $factory = new Factory;
            $text = $factory->run("openssl x509 -in {$paths['cert']} -text -noout")->output();
            $paths2 = $service->issueLeaf('gateway', ['10.6.0.2']);

            expect($text)
                ->toContain('DNS:gateway')
                ->toContain('IP Address:10.6.0.2')
                ->and($paths2)
                ->toBe($paths);
        });

        it('covers short host, browser Gateway hostname, and WireGuard IP for the gateway leaf SAN set', function () {
            $service = new OrbitCaService;
            $paths = $service->issueLeaf('gateway', ['gateway.orbit', '10.6.0.2']);

            $factory = new Factory;
            $text = $factory->run("openssl x509 -in {$paths['cert']} -text -noout")->output();
            $idempotent = $service->issueLeaf('gateway', ['gateway.orbit', '10.6.0.2']);

            expect($text)
                ->toContain('DNS:gateway')
                ->toContain('DNS:gateway.orbit')
                ->toContain('IP Address:10.6.0.2')
                ->and($idempotent)
                ->toBe($paths)
                ->and(orbitCaServiceTestCertSerial($idempotent['cert']))
                ->toBe(orbitCaServiceTestCertSerial($paths['cert']));
        });

        it('reissues a fresh leaf when the browser Gateway hostname SAN is missing', function () {
            $service = new OrbitCaService;
            $paths = $service->issueLeaf('gateway', ['10.6.0.2']);

            $factory = new Factory;
            $initial = $factory->run("openssl x509 -in {$paths['cert']} -text -noout")->output();
            $initialSerial = orbitCaServiceTestCertSerial($paths['cert']);

            $paths2 = $service->issueLeaf('gateway', ['gateway.orbit', '10.6.0.2']);
            $expanded = $factory->run("openssl x509 -in {$paths2['cert']} -text -noout")->output();

            expect($initial)
                ->not->toContain('DNS:gateway.orbit')->and($expanded)->toContain('DNS:gateway')->and(
                    $expanded,
                )->toContain('DNS:gateway.orbit')->and($expanded)->toContain(
                    'IP Address:10.6.0.2',
                )->and(orbitCaServiceTestCertSerial($paths2['cert']))
                ->not->toBe($initialSerial);
        });

        it('reissues a fresh leaf when the requested SAN set expands', function () {
            $service = new OrbitCaService;
            $paths = $service->issueLeaf('gateway');

            $factory = new Factory;
            $initial = $factory->run("openssl x509 -in {$paths['cert']} -text -noout")->output();

            $paths2 = $service->issueLeaf('gateway', ['10.6.0.2']);
            $expanded = $factory->run("openssl x509 -in {$paths2['cert']} -text -noout")->output();

            expect($initial)
                ->not
                ->toContain('IP Address:10.6.0.2')
                ->and($expanded)
                ->toContain('DNS:gateway')
                ->and($expanded)
                ->toContain('IP Address:10.6.0.2');
        });

        it('does not treat SAN prefix matches as existing coverage', function () {
            $service = new OrbitCaService;
            $service->issueLeaf('gateway', ['10.6.0.20']);

            $paths = $service->issueLeaf('gateway', ['10.6.0.2']);

            $factory = new Factory;
            $text = $factory->run("openssl x509 -in {$paths['cert']} -text -noout")->output();

            expect($text)
                ->toContain('DNS:gateway')
                ->and($text)
                ->toContain('IP Address:10.6.0.2')
                ->and($text)
                ->not->toContain('IP Address:10.6.0.20');
        });

        it('refuses path-traversal filenames', function () {
            $service = new OrbitCaService;

            expect(fn () => $service->issueLeaf('../evil'))
                ->toThrow(RuntimeException::class);
        });
    });

    describe('rootCert()', function () {
        it('returns PEM content of root.crt', function () {
            orbitCaServiceTestCreateGatewayNode();

            $caDir = orbitCaServiceTestCaDir();
            $service = new OrbitCaService;
            orbitCaServiceTestSeedValidRootFixture();

            $pem = $service->rootCert();

            expect($pem)->toContain('-----BEGIN CERTIFICATE-----');
            expect($pem)->toBe(File::get("{$caDir}/root.crt"));
        });

        it('throws RuntimeException when root.crt is not a parseable certificate', function () {
            orbitCaServiceTestCreateGatewayNode();

            $service = new OrbitCaService;
            orbitCaServiceTestSeedRootFiles(rootCrt: 'FAKE ROOT');

            expect(fn () => $service->rootCert())
                ->toThrow(RuntimeException::class, 'invalid');
        });

        it('throws RuntimeException when root CA is not bootstrapped', function () {
            $service = new OrbitCaService;

            expect(fn () => $service->rootCert())
                ->toThrow(RuntimeException::class);
        });
    });

    describe('gateway-local guard', function () {
        it('prevents issueLeaf on non-gateway even when CA files exist', function () {
            orbitCaServiceTestCreateGatewayNode();

            $service = new OrbitCaService;
            orbitCaServiceTestSeedRootFiles();

            Node::query()->delete();
            Node::create([
                'name' => 'test-control',
                'tld' => 'test-control',
                'status' => 'active',
                'host' => '127.0.0.1',
                'orbit_path' => base_path(),
            ]);

            expect(fn () => $service->issueLeaf('demo.beast'))
                ->toThrow(RuntimeException::class, 'gateway');
        });

        it('prevents rootCert on non-gateway even when CA files exist', function () {
            orbitCaServiceTestCreateGatewayNode();

            $service = new OrbitCaService;
            orbitCaServiceTestSeedRootFiles();

            Node::query()->delete();
            Node::create([
                'name' => 'test-control',
                'tld' => 'test-control',
                'status' => 'active',
                'host' => '127.0.0.1',
                'orbit_path' => base_path(),
            ]);

            expect(fn () => $service->rootCert())
                ->toThrow(RuntimeException::class, 'gateway');
        });
    });
});
