<?php

declare(strict_types=1);

use App\Services\Dns\ResolvesLocalDns;

// ---------------------------------------------------------------------------
// Test-only fakes
// ---------------------------------------------------------------------------

final class DnsListFakeResolver implements ResolvesLocalDns
{
    public string $platformValue = 'macos';

    public bool $supportedValue = true;

    public bool $mutationSupportedValue = true;

    public bool $dnsmasqInstalledValue = true;

    /** @var array<int, array{tld: string, target: string, source: string, resolver_backend: string, status: string}> */
    public array $overrides = [];

    public bool $throwOnList = false;

    public function platform(): string
    {
        return $this->platformValue;
    }

    public function isSupported(): bool
    {
        return $this->supportedValue;
    }

    public function supportsMutation(): bool
    {
        return $this->mutationSupportedValue;
    }

    public function backend(): string
    {
        return 'dnsmasq';
    }

    /**
     * @return array<int, array{tld: string, target: string, source: string, resolver_backend: string, status: string}>
     */
    public function listOverrides(): array
    {
        if ($this->throwOnList) {
            throw new RuntimeException('read error');
        }

        return $this->overrides;
    }

    public function existingTarget(string $tld): ?string
    {
        return null;
    }

    public function isDnsmasqInstalled(): bool
    {
        return $this->dnsmasqInstalledValue;
    }

    /**
     * @return array{status: string, changed: bool, error?: string}
     */
    public function resolve(string $tld, string $target): array
    {
        return ['status' => 'resolved', 'changed' => true];
    }

    /**
     * @return array{status: string, changed: bool, error?: string}
     */
    public function reset(string $tld): array
    {
        return ['status' => 'reset', 'changed' => true];
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

beforeEach(function (): void {
    $this->resolver = new DnsListFakeResolver;
    app()->instance(ResolvesLocalDns::class, $this->resolver);
});

describe('dns:list', function (): void {
    describe('success — supported platform', function (): void {
        it('returns JSON success with empty list', function (): void {
            [$exitCode, $output] = runCommand($this, 'dns:list', ['--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(0)
                ->and($decoded['success']['data']['dns'])->toBe([])
                ->and($decoded['success']['meta']['count'])->toBe(0)
                ->and($decoded['success']['meta']['resolver_backend'])->toBe('dnsmasq');
        });

        it('returns JSON success with non-empty override list', function (): void {
            $this->resolver->overrides = [
                [
                    'tld' => 'test',
                    'target' => '10.6.0.1',
                    'source' => 'local_resolver',
                    'resolver_backend' => 'dnsmasq',
                    'status' => 'active',
                ],
            ];

            [$exitCode, $output] = runCommand($this, 'dns:list', ['--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(0)
                ->and($decoded['success']['data']['dns'])->toBe($this->resolver->overrides)
                ->and($decoded['success']['meta']['count'])->toBe(1)
                ->and($decoded['success']['meta']['resolver_backend'])->toBe('dnsmasq');
        });

        it('renders human output without error for non-empty list', function (): void {
            $this->resolver->overrides = [
                [
                    'tld' => 'test',
                    'target' => '10.6.0.1',
                    'source' => 'local_resolver',
                    'resolver_backend' => 'dnsmasq',
                    'status' => 'active',
                ],
            ];

            [$exitCode] = runCommand($this, 'dns:list');

            expect($exitCode)->toBe(0);
        });
    });

    describe('failure cases', function (): void {
        it('returns node.unsupported_platform when resolver is not supported', function (): void {
            $this->resolver->supportedValue = false;
            $this->resolver->platformValue = 'unsupported';

            [$exitCode, $output] = runCommand($this, 'dns:list', ['--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(1)
                ->and($decoded['error']['code'])->toBe('node.unsupported_platform')
                ->and($decoded['error']['meta']['platform'])->toBe('unsupported');
        });

        it('returns local_resolver_read_failed when listOverrides throws', function (): void {
            $this->resolver->throwOnList = true;

            [$exitCode, $output] = runCommand($this, 'dns:list', ['--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(1)
                ->and($decoded['error']['code'])->toBe('local_resolver_read_failed')
                ->and($decoded['error']['meta']['resolver_backend'])->toBe('dnsmasq');
        });
    });
});
