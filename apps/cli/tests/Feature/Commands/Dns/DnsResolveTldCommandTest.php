<?php

declare(strict_types=1);

use App\Services\Dns\ResolvesLocalDns;

// ---------------------------------------------------------------------------
// Test-only fakes
// ---------------------------------------------------------------------------

final class DnsResolveFakeResolver implements ResolvesLocalDns
{
    public string $platformValue = 'macos';

    public bool $supportedValue = true;

    public bool $mutationSupportedValue = true;

    public bool $dnsmasqInstalledValue = true;

    public ?string $existingTargetValue = null;

    /** @var array{status: string, changed: bool, error?: string} */
    public array $resolveResult = ['status' => 'resolved', 'changed' => true];

    /** @var array{status: string, changed: bool, error?: string} */
    public array $resetResult = ['status' => 'reset', 'changed' => true];

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
        return [];
    }

    public function existingTarget(string $tld): ?string
    {
        return $this->existingTargetValue;
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
        return $this->resolveResult;
    }

    /**
     * @return array{status: string, changed: bool, error?: string}
     */
    public function reset(string $tld): array
    {
        return $this->resetResult;
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

beforeEach(function (): void {
    $this->resolver = new DnsResolveFakeResolver;
    app()->instance(ResolvesLocalDns::class, $this->resolver);
});

describe('dns:resolve-tld', function (): void {
    describe('resolve sub-action', function (): void {
        it('returns JSON success on resolve', function (): void {
            [$exitCode, $output] = runCommand($this, 'dns:resolve-tld', ['tld' => 'test', 'target' => '10.6.0.1', '--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(0)
                ->and($decoded['success']['data']['dns']['tld'])->toBe('test')
                ->and($decoded['success']['data']['dns']['target'])->toBe('10.6.0.1')
                ->and($decoded['success']['data']['dns']['action'])->toBe('resolve')
                ->and($decoded['success']['data']['dns']['status'])->toBe('resolved')
                ->and($decoded['success']['data']['dns']['changed'])->toBeTrue();
        });

        it('prompts for Target IP address in interactive mode when target is missing (Item 4 gap)', function (): void {
            $this->artisan('dns:resolve-tld', ['tld' => 'test'])
                ->expectsQuestion('Target IP address', '10.6.0.7')
                ->assertExitCode(0);
        });

        it('returns refresh failure diagnostics and partial DNS data', function (): void {
            $this->resolver->resolveResult = [
                'status' => 'refresh_failed',
                'changed' => true,
                'error' => 'dnsmasq did not return 192.168.1.150 for orbit-local-resolver-health.test.',
            ];

            [$exitCode, $output] = runCommand($this, 'dns:resolve-tld', ['tld' => 'test', 'target' => '192.168.1.150', '--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(1)
                ->and($decoded['error']['code'])->toBe('local_resolver_refresh_failed')
                ->and($decoded['error']['meta']['diagnostics'])->toContain('dnsmasq did not return 192.168.1.150')
                ->and($decoded['error']['data']['dns'])->toMatchArray([
                    'tld' => 'test',
                    'target' => '192.168.1.150',
                    'action' => 'resolve',
                    'status' => 'refresh_failed',
                    'changed' => true,
                    'source' => 'local_resolver',
                    'resolver_backend' => 'dnsmasq',
                ]);
        });
    });

    describe('reset sub-action', function (): void {
        it('returns JSON success on reset when already absent (idempotent)', function (): void {
            $this->resolver->resetResult = ['status' => 'already_absent', 'changed' => false];

            [$exitCode, $output] = runCommand($this, 'dns:resolve-tld', ['tld' => 'test', '--reset' => true, '--force' => true, '--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(0)
                ->and($decoded['success']['data']['dns']['tld'])->toBe('test')
                ->and($decoded['success']['data']['dns']['action'])->toBe('reset')
                ->and($decoded['success']['data']['dns']['status'])->toBe('already_absent')
                ->and($decoded['success']['data']['dns']['changed'])->toBeFalse();
        });

        it('succeeds with --reset --force in non-interactive mode', function (): void {
            [$exitCode, $output] = runCommand($this, 'dns:resolve-tld', ['tld' => 'test', '--reset' => true, '--force' => true, '--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(0)
                ->and($decoded['success']['data']['dns']['status'])->toBe('reset');
        });
    });

    describe('validation failure cases', function (): void {
        it('returns validation_failed for missing tld non-interactively', function (): void {
            [$exitCode, $output] = runCommand($this, 'dns:resolve-tld', ['--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(1)
                ->and($decoded['error']['code'])->toBe('validation_failed')
                ->and($decoded['error']['meta']['field'])->toBe('tld')
                ->and($decoded['error']['meta']['reason'])->toBe('missing');
        });

        it('returns validation_failed for missing target non-interactively', function (): void {
            [$exitCode, $output] = runCommand($this, 'dns:resolve-tld', ['tld' => 'test', '--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(1)
                ->and($decoded['error']['code'])->toBe('validation_failed')
                ->and($decoded['error']['meta']['field'])->toBe('target')
                ->and($decoded['error']['meta']['reason'])->toBe('missing');
        });

        it('returns destructive_consent_required for --reset without --force non-interactively', function (): void {
            [$exitCode, $output] = runCommand($this, 'dns:resolve-tld', ['tld' => 'test', '--reset' => true, '--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(1)
                ->and($decoded['error']['code'])->toBe('destructive_consent_required');
        });

        it('returns node.unsupported_platform when resolver does not support mutation', function (): void {
            $this->resolver->mutationSupportedValue = false;
            $this->resolver->platformValue = 'linux';

            [$exitCode, $output] = runCommand($this, 'dns:resolve-tld', ['tld' => 'test', 'target' => '10.6.0.1', '--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(1)
                ->and($decoded['error']['code'])->toBe('node.unsupported_platform')
                ->and($decoded['error']['meta']['platform'])->toBe('linux');
        });
    });

    describe('human render', function (): void {
        it('renders the resolve progress tree with a resolved success line', function (): void {
            $this->resolver->resolveResult = ['status' => 'resolved', 'changed' => true];

            [$exitCode, $output] = runCommand($this, 'dns:resolve-tld', ['tld' => 'test', 'target' => '10.6.0.7']);

            expect($exitCode)->toBe(0)
                ->and($output)->toContain('Configuring Local DNS')
                ->and($output)->toContain('Validate .test')
                ->and($output)->toContain('Write resolver override')
                ->and($output)->toContain('Refresh resolver')
                ->and($output)->toContain('.test resolves to 10.6.0.7.')
                ->and($output)->not->toContain('{')
                ->and($output)->not->toContain('dns:');
        });

        it('renders the already-converged resolve tree without a write step', function (): void {
            $this->resolver->existingTargetValue = '10.6.0.7';
            $this->resolver->resolveResult = ['status' => 'already_resolved', 'changed' => false];

            [$exitCode, $output] = runCommand($this, 'dns:resolve-tld', ['tld' => 'test', 'target' => '10.6.0.7']);

            expect($exitCode)->toBe(0)
                ->and($output)->toContain('Configuring Local DNS')
                ->and($output)->toContain('Validate .test')
                ->and($output)->toContain('Check resolver override')
                ->and($output)->toContain('.test already resolves to 10.6.0.7.')
                ->and($output)->not->toContain('Write resolver override');
        });

        it('renders the reset progress tree with a removed success line', function (): void {
            $this->resolver->existingTargetValue = '10.6.0.7';
            $this->resolver->resetResult = ['status' => 'reset', 'changed' => true];

            [$exitCode, $output] = runCommand($this, 'dns:resolve-tld', ['tld' => 'test', '--reset' => true, '--force' => true]);

            expect($exitCode)->toBe(0)
                ->and($output)->toContain('Resetting Local DNS')
                ->and($output)->toContain('Validate .test')
                ->and($output)->toContain('Remove resolver override')
                ->and($output)->toContain('Refresh resolver')
                ->and($output)->toContain('.test resolver override removed.')
                ->and($output)->not->toContain('{');
        });

        it('renders the already-absent reset tree without a remove step', function (): void {
            $this->resolver->resetResult = ['status' => 'already_absent', 'changed' => false];

            [$exitCode, $output] = runCommand($this, 'dns:resolve-tld', ['tld' => 'test', '--reset' => true, '--force' => true]);

            expect($exitCode)->toBe(0)
                ->and($output)->toContain('Resetting Local DNS')
                ->and($output)->toContain('Validate .test')
                ->and($output)->toContain('Check resolver override')
                ->and($output)->toContain('.test resolver override already absent.')
                ->and($output)->not->toContain('Remove resolver override');
        });

        it('renders resolver write failures as prose in the failing tree', function (): void {
            $this->resolver->resolveResult = ['status' => 'write_failed', 'changed' => false];

            [$exitCode, $output] = runCommand($this, 'dns:resolve-tld', ['tld' => 'test', 'target' => '10.6.0.7']);

            expect($exitCode)->toBe(1)
                ->and($output)->toContain('Failed to update local DNS resolver configuration.')
                ->and($output)->not->toContain('{')
                ->and($output)->not->toContain('local_resolver_write_failed:');
        });

        it('renders resolver refresh failures as prose in the failing tree', function (): void {
            $this->resolver->resolveResult = [
                'status' => 'refresh_failed',
                'changed' => true,
                'error' => 'dnsmasq did not return 10.6.0.7.',
            ];

            [$exitCode, $output] = runCommand($this, 'dns:resolve-tld', ['tld' => 'test', 'target' => '10.6.0.7']);

            expect($exitCode)->toBe(1)
                ->and($output)->toContain('Local DNS resolver configuration changed, but the resolver could not be refreshed.')
                ->and($output)->not->toContain('{');
        });

        it('renders unsupported-platform failures as prose without a JSON envelope', function (): void {
            $this->resolver->mutationSupportedValue = false;
            $this->resolver->platformValue = 'linux';

            [$exitCode, $output] = runCommand($this, 'dns:resolve-tld', ['tld' => 'test', 'target' => '10.6.0.7']);

            expect($exitCode)->toBe(1)
                ->and($output)->toContain('This platform does not support automatic local DNS resolver configuration.')
                ->and($output)->not->toContain('{');
        });
    });
});
