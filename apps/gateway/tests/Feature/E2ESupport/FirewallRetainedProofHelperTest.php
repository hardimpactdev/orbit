<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    require_once repo_path('bin/orbit-firewall-retained-proof.php');
});

it('rejects uppercase-ineligible topology names and synthetic proof prefixes', function (): void {
    expect(fn () => firewall_proof_bind([
        'candidate' => str_repeat('a', times: 40),
        'local_checkout_digest' => str_repeat('b', times: 64),
        'remote_checkout_digest' => str_repeat('b', times: 64),
        'target' => 'orbit-e2e-fw-proof-aaaaaaaaaaaa',
        'host' => 'beast',
        'instances' => [
            'operator' => 'orbit-e2e-fw-proof-aaaaaaaaaaaa-operator',
            'gateway' => 'orbit-e2e-fw-proof-aaaaaaaaaaaa-gateway',
            'dev' => 'orbit-e2e-fw-proof-aaaaaaaaaaaa-dev',
        ],
    ]))
        ->toThrow(InvalidArgumentException::class, 'target identity mismatch');
});

it('fails closed when local and remote checkout digests diverge', function (): void {
    $bound = firewall_proof_bind([
        'candidate' => str_repeat('a', times: 40),
        'local_checkout_digest' => str_repeat('b', times: 64),
        'remote_checkout_digest' => str_repeat('b', times: 64),
        'target' => 'dev-501dc2',
        'host' => 'beast',
        'instances' => [
            'operator' => 'orbit-e2e-dev-501dc2-operator',
            'gateway' => 'orbit-e2e-dev-501dc2-gateway',
            'dev' => 'orbit-e2e-dev-501dc2-dev',
        ],
    ]);

    expect($bound['checkout_digest'])
        ->toBe(str_repeat('b', times: 64))
        ->and($bound['binding'])
        ->toBe(FIREWALL_PROOF_BINDING)
        ->and($bound)
        ->not->toHaveKey('cli_digest')->and($bound)
        ->not->toHaveKey('remote_head');

    expect(fn () => firewall_proof_bind([
        'candidate' => str_repeat('a', times: 40),
        'local_checkout_digest' => str_repeat('b', times: 64),
        'remote_checkout_digest' => str_repeat('d', times: 64),
        'target' => 'dev-501dc2',
        'host' => 'beast',
        'instances' => [
            'operator' => 'orbit-e2e-dev-501dc2-operator',
            'gateway' => 'orbit-e2e-dev-501dc2-gateway',
            'dev' => 'orbit-e2e-dev-501dc2-dev',
        ],
    ]))
        ->toThrow(InvalidArgumentException::class, 'checkout digest mismatch');
});

it('includes synced paths outside the former firewall whitelist', function (): void {
    $paths = firewall_proof_list_synced_paths(repo_path());

    expect($paths)
        ->toContain('apps/cli/app/Commands/Firewall/FirewallAllowCommand.php')
        ->and($paths)
        ->toContain('packages/sdk/src/Requests/Firewall/StoreFirewallRuleRequest.php')
        ->and($paths)
        ->toContain('apps/gateway/app/Http/Controllers/Api/FirewallRuleStoreController.php');
});

it('fails closed when git ls-files fails or returns an empty tracked set', function (): void {
    $missingGit = sys_get_temp_dir().'/firewall-proof-no-git-'.bin2hex(random_bytes(4));
    $emptyGit = sys_get_temp_dir().'/firewall-proof-empty-git-'.bin2hex(random_bytes(4));
    mkdir($missingGit);
    mkdir($emptyGit);

    try {
        $init = firewall_proof_run_command(['git', 'init'], $emptyGit, timeout: 10);

        expect($init['exit'])
            ->toBe(0)
            ->and(fn () => firewall_proof_list_synced_paths($missingGit))
            ->toThrow(InvalidArgumentException::class, 'checkout digest mismatch')
            ->and(fn () => firewall_proof_list_synced_paths($emptyGit))
            ->toThrow(InvalidArgumentException::class, 'checkout digest mismatch');
    } finally {
        File::deleteDirectory($missingGit);
        File::deleteDirectory($emptyGit);
    }
});

it('fails closed on a missing extra or mismatched synced path', function (): void {
    expect(fn () => firewall_proof_assert_synced_trees(
        ['apps/cli/orbit' => str_repeat('a', times: 64)],
        [],
    ))
        ->toThrow(InvalidArgumentException::class, 'checkout digest mismatch');

    expect(fn () => firewall_proof_assert_synced_trees(
        ['apps/cli/orbit' => str_repeat('a', times: 64)],
        [
            'apps/cli/orbit' => str_repeat('a', times: 64),
            'packages/sdk/src/GatewayRequest.php' => str_repeat('b', times: 64),
        ],
    ))
        ->toThrow(InvalidArgumentException::class, 'checkout digest mismatch');

    expect(fn () => firewall_proof_assert_synced_trees(
        ['apps/cli/orbit' => str_repeat('a', times: 64)],
        ['apps/cli/orbit' => str_repeat('b', times: 64)],
    ))
        ->toThrow(InvalidArgumentException::class, 'checkout digest mismatch');
});

it('treats remote-only paths as extras and ignores local ignored files', function (): void {
    $root = sys_get_temp_dir().'/firewall-proof-extras-'.bin2hex(random_bytes(4));

    try {
        mkdir($root.'/ignored', recursive: true);
        file_put_contents($root.'/ignored/local.txt', data: 'keep');

        $parsed = firewall_proof_parse_remote_tree_output(
            "H\tapps/cli/orbit\t"
            .str_repeat('a', times: 64)
            ."\n"
            ."H\tmissing.txt\t\n"
            ."E\tignored/local.txt\n"
            ."E\tleftover-tracked.php\n",
        );

        expect($parsed['files'])
            ->toHaveKey('apps/cli/orbit')
            ->not
            ->toHaveKey('missing.txt')
            ->and($parsed['extras'])
            ->toContain('ignored/local.txt')
            ->toContain('leftover-tracked.php');

        $remote = firewall_proof_include_unexplained_extras(
            $parsed['files'],
            $parsed['extras'],
            $root,
        );

        expect($remote)
            ->toHaveKey('apps/cli/orbit')
            ->toHaveKey('leftover-tracked.php')
            ->not->toHaveKey('ignored/local.txt');

        expect(fn () => firewall_proof_assert_synced_trees(
            ['apps/cli/orbit' => str_repeat('a', times: 64)],
            $remote,
        ))
            ->toThrow(InvalidArgumentException::class, 'checkout digest mismatch');
    } finally {
        unlink($root.'/ignored/local.txt');
        rmdir($root.'/ignored');
        rmdir($root);
    }
});

it('validates retained state identity before a sync would run', function (): void {
    $state = [
        'candidate' => str_repeat('a', times: 40),
        'binding' => FIREWALL_PROOF_BINDING,
        'checkout_digest' => str_repeat('b', times: 64),
        'target' => 'dev-501dc2',
        'host' => 'beast',
        'instances' => [
            'operator' => 'orbit-e2e-dev-501dc2-operator',
            'gateway' => 'orbit-e2e-dev-501dc2-gateway',
            'dev' => 'orbit-e2e-dev-501dc2-dev',
        ],
    ];

    expect(firewall_proof_validate_state($state, str_repeat('a', times: 40))['target'])
        ->toBe('dev-501dc2');

    expect(fn () => firewall_proof_validate_state($state, str_repeat('c', times: 40)))
        ->toThrow(InvalidArgumentException::class, 'candidate SHA mismatch');
});

it('refuses to stop the shared retained topology', function (): void {
    expect(fn () => firewall_proof_refuse_shared_topology_stop())
        ->toThrow(RuntimeException::class, 'shared topology stop refused');
});

it('keeps duplicate managed comments distinct and requires the exact allow source', function (): void {
    $rules = firewall_proof_parse_ufw(<<<'UFW'
        Status: active

             To                         Action      From
             --                         ------      ----
        [ 1] 8080/tcp                   ALLOW IN    10.9.9.9/32                 # orbit:private-api
        [ 2] 8080/tcp                   ALLOW IN    192.168.1.0/24              # orbit:private-api
        [ 3] 8080/tcp                   ALLOW IN    10.6.0.0/24                 # protected unrelated rule
        [ 4] 8080/tcp                   DENY IN     Anywhere
        UFW);

    expect(firewall_proof_managed_port_rules($rules))
        ->toHaveCount(2)
        ->and(fn () => firewall_proof_assert_single_managed_source($rules, FIREWALL_PROOF_SOURCE))
        ->toThrow(RuntimeException::class, 'failed-step=allow managed source mismatch');
});

it('accepts a single replaced managed allow that precedes deny', function (): void {
    $rules = firewall_proof_parse_ufw(<<<'UFW'
        Status: active

             To                         Action      From
             --                         ------      ----
        [ 1] 8080/tcp                   ALLOW IN    192.168.1.0/24              # orbit:private-api
        [ 2] 8080/tcp                   ALLOW IN    10.6.0.0/24                 # protected unrelated rule
        [ 3] 8080/tcp                   DENY IN     Anywhere
        UFW);

    firewall_proof_assert_single_managed_source($rules, FIREWALL_PROOF_SOURCE);

    expect(firewall_proof_managed_allow_precedes_deny($rules))
        ->toBeTrue()
        ->and(firewall_proof_seed_cleanup_indexes($rules))
        ->toBe([3, 2, 1]);
});

it('names allow and doctor failures as failed steps', function (): void {
    expect(fn () => firewall_proof_assert_allow_succeeded(['error' => ['code' => 'firewall_rule.enactment_failed']]))
        ->toThrow(RuntimeException::class, 'failed-step=allow')
        ->and(fn () => firewall_proof_assert_doctor_healthy(['healthy' => false]))
        ->toThrow(RuntimeException::class, 'failed-step=doctor');
});

it('keeps process stdout and stderr separate under a timeout', function (): void {
    $result = firewall_proof_run_command(
        [PHP_BINARY, '-r', 'fwrite(STDOUT, "out"); fwrite(STDERR, "err");'],
        repo_path(),
        10,
    );

    expect($result['exit'])
        ->toBe(0)
        ->and($result['stdout'])
        ->toBe('out')
        ->and($result['stderr'])
        ->toBe('err');
});
