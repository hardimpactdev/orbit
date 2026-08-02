<?php

declare(strict_types=1);

use Orbit\Core\Caddy\CaddyfileLocalCaIntermediateLifetime;

it('removes intermediate_lifetime 3599d only from ca local and drops empty wrappers', function (): void {
    $input = <<<'CADDY'
        {
            local_certs
            admin localhost:2019
            pki {
                ca local {
                    intermediate_lifetime 3599d
                }
            }
        }
        CADDY;

    $output = CaddyfileLocalCaIntermediateLifetime::withoutObsoleteLocalOverride($input);

    expect($output)
        ->not->toContain('intermediate_lifetime 3599d')
        ->not->toContain('ca local')
        ->not->toContain('pki {')->toContain('local_certs')->toContain('admin localhost:2019');
});

it('preserves intermediate_lifetime 3599d under a non-local custom CA', function (): void {
    $input = <<<'CADDY'
        {
            local_certs
            pki {
                ca custom {
                    intermediate_lifetime 3599d
                }
                ca other {
                    intermediate_lifetime 7d
                }
            }
        }
        CADDY;

    $output = CaddyfileLocalCaIntermediateLifetime::withoutObsoleteLocalOverride($input);

    expect($output)
        ->toContain('ca custom')
        ->toContain('intermediate_lifetime 3599d')
        ->toContain('ca other')
        ->toContain('intermediate_lifetime 7d')
        ->toContain('pki {');
});

it('removes empty ca local while preserving a sibling custom CA with 3599d and normal indentation', function (): void {
    $input = <<<'CADDY'
        {
            pki {
                ca local {
                    intermediate_lifetime 3599d
                }
                ca custom {
                    intermediate_lifetime 3599d
                }
            }
        }
        CADDY;

    $output = CaddyfileLocalCaIntermediateLifetime::withoutObsoleteLocalOverride($input);

    expect($output)
        ->not->toContain('ca local')->toContain(
            "ca custom {\n            intermediate_lifetime 3599d\n        }",
        )->toContain('pki {')
        ->not->toMatch('/pki \{\n {16}ca custom/');
});

it('keeps other ca local settings while removing only intermediate_lifetime 3599d', function (): void {
    $input = <<<'CADDY'
        {
            local_certs
            pki {
                ca local {
                    intermediate_lifetime 3599d
                    root_common_name "Orbit Local Root"
                }
            }
        }
        CADDY;

    $output = CaddyfileLocalCaIntermediateLifetime::withoutObsoleteLocalOverride($input);

    expect($output)
        ->not
        ->toContain('intermediate_lifetime 3599d')
        ->toContain('ca local')
        ->toContain('root_common_name "Orbit Local Root"')
        ->toContain('pki {');
});

it('does not remove intermediate_lifetime 3599d outside a ca local block', function (): void {
    $input = <<<'CADDY'
        {
            local_certs
            intermediate_lifetime 3599d
        }

        site.example {
            intermediate_lifetime 3599d
        }
        CADDY;

    $output = CaddyfileLocalCaIntermediateLifetime::withoutObsoleteLocalOverride($input);

    expect($output)->toBe($input);
});
