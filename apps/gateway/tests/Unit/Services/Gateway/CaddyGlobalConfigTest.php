<?php

declare(strict_types=1);

use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Services\Gateway\CaddyGlobalConfig;
use App\Services\Proxy\ProxyRouteProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('CaddyGlobalConfig obsolete intermediate_lifetime 3599d migration', function (): void {
    it('removes intermediate_lifetime 3599d only under ca local and drops empty wrappers', function (): void {
        $legacy = <<<'CADDY'
            {
                local_certs
                admin localhost:2019
                pki {
                    ca local {
                        intermediate_lifetime 3599d
                    }
                }
            }

            import /etc/caddy/orbit/*.caddy
            import /etc/caddy/sites/*.caddy
            CADDY;

        $ensured = new CaddyGlobalConfig()->ensure($legacy);

        expect($ensured)
            ->not->toContain('intermediate_lifetime 3599d')
            ->not->toContain('intermediate_lifetime')
            ->not->toContain('pki {')
            ->not->toContain('ca local')->toContain('local_certs')->toContain('admin localhost:2019')->toContain(
                'import /etc/caddy/orbit/*.caddy',
            )->toContain('import /etc/caddy/sites/*.caddy');
    });

    it('preserves intermediate_lifetime 3599d under a non-local custom CA', function (): void {
        $custom = <<<'CADDY'
            {
                local_certs
                admin localhost:2019
                pki {
                    ca custom {
                        intermediate_lifetime 3599d
                    }
                    ca other {
                        intermediate_lifetime 7d
                    }
                }
            }

            custom.example {
                respond ok
            }
            CADDY;

        $ensured = new CaddyGlobalConfig()->ensure($custom);

        expect($ensured)
            ->toContain('ca custom')
            ->toContain('intermediate_lifetime 3599d')
            ->toContain('ca other')
            ->toContain('intermediate_lifetime 7d')
            ->toContain('custom.example');
    });

    it('keeps other ca local settings while removing only intermediate_lifetime 3599d', function (): void {
        $mixed = <<<'CADDY'
            {
                local_certs
                admin localhost:2019
                pki {
                    ca local {
                        intermediate_lifetime 3599d
                        root_common_name "Orbit Local Root"
                    }
                }
            }
            CADDY;

        $ensured = new CaddyGlobalConfig()->ensure($mixed);

        expect($ensured)
            ->not
            ->toContain('intermediate_lifetime 3599d')
            ->toContain('ca local')
            ->toContain('root_common_name "Orbit Local Root"')
            ->toContain('pki {');
    });

    it('preserves non-matching intermediate_lifetime values and unrelated custom options', function (): void {
        $custom = <<<'CADDY'
            {
                local_certs
                admin localhost:2019
                email ops@example.com
                pki {
                    ca local {
                        intermediate_lifetime 7d
                    }
                    ca custom {
                        intermediate_lifetime 30d
                    }
                }
            }

            custom.example {
                respond ok
            }
            CADDY;

        $ensured = new CaddyGlobalConfig()->ensure($custom);

        expect($ensured)
            ->toContain('intermediate_lifetime 7d')
            ->toContain('intermediate_lifetime 30d')
            ->toContain('email ops@example.com')
            ->toContain('ca custom')
            ->toContain('custom.example')
            ->not->toContain('intermediate_lifetime 3599d');
    });

    it('only rewrites Caddyfile text and never references PEM storage paths', function (): void {
        $legacy = <<<'CADDY'
            {
                local_certs
                pki {
                    ca local {
                        intermediate_lifetime 3599d
                    }
                }
            }
            CADDY;

        $ensured = new CaddyGlobalConfig()->ensure($legacy);

        expect($ensured)
            ->not->toContain('/var/lib/orbit/caddy/data')
            ->not->toContain('root.crt')
            ->not->toContain('root.key')
            ->not->toContain('intermediate.crt')
            ->not->toContain('intermediate.key')
            ->not->toContain('intermediate_lifetime 3599d');
    });

    it('makes Doctor report global_config_mismatch for the exact ca local 3599d block', function (): void {
        $node = Node::factory()->create([
            'name' => 'mini',
            'status' => 'active',
            'tld' => 'mini',
        ]);
        $legacy = <<<'CADDY'
            {
                local_certs
                admin localhost:2019
                pki {
                    ca local {
                        intermediate_lifetime 3599d
                    }
                }
            }

            import /etc/caddy/orbit/*.caddy
            import /etc/caddy/sites/*.caddy
            CADDY;

        $ensured = new CaddyGlobalConfig()->ensure($legacy);

        expect($ensured)
            ->not->toBe($legacy)
            ->not->toContain('intermediate_lifetime 3599d');

        $drift = new ProxyRouteProbe()->diffGlobalConfig($node, new ProbeSnapshot([
            'global_caddy_config' => [
                'exists' => true,
                'content' => $legacy,
                'hash' => hash('sha256', $legacy),
            ],
        ]));

        $issue = collect($drift)->first(fn ($entry): bool => $entry->key === 'proxy.global_config_mismatch');

        expect($issue?->kind)->toBe(DriftKind::Divergent);
    });

    it('recreates the Mini 3599d fixture while preserving an aged root fingerprint on disk', function (): void {
        $fixture = sys_get_temp_dir().'/orbit-caddy-3599d-'.bin2hex(random_bytes(4));
        $pkiDir = "{$fixture}/data/pki/authorities/local";
        mkdir($pkiDir, recursive: true);

        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        expect($privateKey === false)->toBeFalse();

        $csr = openssl_csr_new(['commonName' => 'Orbit Mini Fixture Root'], $privateKey);
        expect($csr === false)->toBeFalse();

        // ~3579d remaining ≈ aged 10y root with ~85901h left.
        $rootCertificate = openssl_csr_sign(
            csr: $csr,
            ca_certificate: null,
            private_key: $privateKey,
            days: 3579,
        );
        expect($rootCertificate === false)->toBeFalse();

        $rootPem = '';
        openssl_x509_export($rootCertificate, $rootPem);
        $rootKeyPem = '';
        openssl_pkey_export($privateKey, $rootKeyPem);
        $intermediatePem = "-----BEGIN CERTIFICATE-----\nfixture-intermediate\n-----END CERTIFICATE-----\n";

        file_put_contents("{$pkiDir}/root.crt", $rootPem);
        file_put_contents("{$pkiDir}/root.key", $rootKeyPem);
        file_put_contents("{$pkiDir}/intermediate.crt", $intermediatePem);
        file_put_contents(data: "fixture-intermediate-key\n", filename: "{$pkiDir}/intermediate.key");
        $rootFingerprintBefore = hash_file('sha256', "{$pkiDir}/root.crt");
        $rootKeyFingerprintBefore = hash_file('sha256', "{$pkiDir}/root.key");
        $intermediateFingerprintBefore = hash_file('sha256', "{$pkiDir}/intermediate.crt");

        $legacyCaddyfile = <<<'CADDY'
            {
                local_certs
                admin localhost:2019
                pki {
                    ca local {
                        intermediate_lifetime 3599d
                    }
                }
            }

            custom.mini {
                respond ok
            }
            CADDY;
        file_put_contents("{$fixture}/Caddyfile", $legacyCaddyfile);

        $rewritten = new CaddyGlobalConfig()->ensure($legacyCaddyfile);
        file_put_contents("{$fixture}/Caddyfile", $rewritten);

        expect($rewritten)
            ->not
            ->toContain('intermediate_lifetime 3599d')
            ->toContain('custom.mini')
            ->and(hash_file('sha256', "{$pkiDir}/root.crt"))
            ->toBe($rootFingerprintBefore)
            ->and(hash_file('sha256', "{$pkiDir}/root.key"))
            ->toBe($rootKeyFingerprintBefore)
            ->and(hash_file('sha256', "{$pkiDir}/intermediate.crt"))
            ->toBe($intermediateFingerprintBefore)
            ->and(file_get_contents("{$fixture}/Caddyfile"))
            ->toBe($rewritten);

        $pkiFiles = glob("{$pkiDir}/*");

        foreach (is_array($pkiFiles) ? $pkiFiles : [] as $file) {
            unlink($file);
        }

        $cursor = $pkiDir;

        while ($cursor !== $fixture && is_dir($cursor)) {
            $entries = scandir($cursor);

            if (is_array($entries) && count($entries) === 2) {
                rmdir($cursor);
            }

            $cursor = dirname($cursor);
        }

        if (is_file("{$fixture}/Caddyfile")) {
            unlink("{$fixture}/Caddyfile");
        }

        if (is_dir($fixture)) {
            $fixtureEntries = scandir($fixture);

            if (is_array($fixtureEntries) && count($fixtureEntries) === 2) {
                rmdir($fixture);
            }
        }
    });
});
