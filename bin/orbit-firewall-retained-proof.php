<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

const FIREWALL_PROOF_HOST = 'beast';
const FIREWALL_PROOF_RULE_NAME = 'private-api';
const FIREWALL_PROOF_NODE = 'app-dev-1';
const FIREWALL_PROOF_PORT = '8080';
const FIREWALL_PROOF_PROTOCOL = 'tcp';
const FIREWALL_PROOF_SOURCE = '192.168.1.0/24';
const FIREWALL_PROOF_STALE_SOURCE = '10.9.9.9/32';
const FIREWALL_PROOF_PROTECTED_SOURCE = '10.6.0.0/24';
const FIREWALL_PROOF_PROTECTED_COMMENT = 'protected unrelated rule';
const FIREWALL_PROOF_MANAGED_IDENTITY = 'orbit:private-api';

/**
 * @return list<string>
 */
function firewall_proof_checkout_paths(): array
{
    return [
        'apps/cli/orbit',
        'apps/cli/app/Services/Firewall/LocalFirewallRuleAction.php',
        'apps/gateway/app/Services/Firewall/FirewallTargetPlatform.php',
        'apps/gateway/app/Services/Firewall/FirewallRuleProbe.php',
        'apps/gateway/app/Services/Firewall/FirewallRuleIntent.php',
        'apps/gateway/app/Services/Firewall/FirewallRuleQuery.php',
        'apps/gateway/app/Services/Firewall/FirewallRuleShapeCanonicalizer.php',
        'apps/gateway/app/Services/Convergence/UfwFirewallRule.php',
        'apps/gateway/app/Services/Doctor/DoctorAdoptPolicy.php',
        'apps/gateway/app/Services/Doctor/DoctorNodeFamilyResolver.php',
        'apps/gateway/app/Services/Security/PublicSshDenyInstaller.php',
        'packages/core/src/Firewall/ManagedUfwComment.php',
        'bin/orbit-firewall-retained-proof',
        'bin/orbit-firewall-retained-proof.php',
    ];
}

function firewall_proof_require_root_autoload(string $root): void
{
    $autoload = $root.'/vendor/autoload.php';

    if (! is_file($autoload)) {
        throw new RuntimeException('root composer autoload unavailable');
    }

    require $autoload;
}

function firewall_proof_require_process_autoload(string $root): void
{
    if (class_exists(Process::class)) {
        return;
    }

    $cliAutoload = $root.'/apps/cli/vendor/autoload.php';

    if (! is_file($cliAutoload)) {
        throw new RuntimeException('cli composer autoload unavailable');
    }

    require $cliAutoload;

    if (! class_exists(Process::class)) {
        throw new RuntimeException('symfony process unavailable');
    }
}

function firewall_proof_require_sha(string $value): void
{
    if (preg_match('/^[a-f0-9]{40}$/', $value) !== 1) {
        throw new InvalidArgumentException('candidate SHA mismatch');
    }
}

function firewall_proof_require_digest(string $value): void
{
    if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
        throw new InvalidArgumentException('checkout digest mismatch');
    }
}

function firewall_proof_digest_checkout(string $root): string
{
    $parts = [];

    foreach (firewall_proof_checkout_paths() as $relative) {
        $digest = hash_file('sha256', rtrim($root, characters: '/').'/'.$relative);

        if (! is_string($digest)) {
            throw new InvalidArgumentException('checkout digest mismatch');
        }

        $parts[] = $relative.':'.$digest;
    }

    return hash('sha256', implode("\n", $parts));
}

/**
 * @param  array<string, string>  $instances
 */
function firewall_proof_assert_owned_instances(string $topologyId, array $instances): void
{
    if (preg_match('/^dev-[a-f0-9]{6}$/', $topologyId) !== 1) {
        throw new InvalidArgumentException('target identity mismatch');
    }

    foreach (['operator', 'gateway', 'dev'] as $role) {
        if (($instances[$role] ?? '') !== 'orbit-e2e-'.$topologyId.'-'.$role) {
            throw new InvalidArgumentException('target identity mismatch');
        }
    }
}

/**
 * @param  array<string, mixed>  $observed
 * @return array{candidate: string, checkout_digest: string, target: string, host: string, instances: array{operator: string, gateway: string, dev: string}}
 */
function firewall_proof_bind(array $observed): array
{
    $candidate = is_string($observed['candidate'] ?? null) ? $observed['candidate'] : '';
    $localDigest = is_string($observed['local_checkout_digest'] ?? null) ? $observed['local_checkout_digest'] : '';
    $remoteDigest = is_string($observed['remote_checkout_digest'] ?? null) ? $observed['remote_checkout_digest'] : '';
    $target = is_string($observed['target'] ?? null) ? $observed['target'] : '';
    $host = is_string($observed['host'] ?? null) ? strtolower($observed['host']) : '';
    $instances = is_array($observed['instances'] ?? null) ? $observed['instances'] : [];
    $remoteHead = $observed['remote_head'] ?? null;

    firewall_proof_require_sha($candidate);
    firewall_proof_require_digest($localDigest);
    firewall_proof_require_digest($remoteDigest);
    firewall_proof_assert_owned_instances($target, $instances);

    if ($host !== FIREWALL_PROOF_HOST) {
        throw new InvalidArgumentException('target identity mismatch');
    }

    if ($localDigest !== $remoteDigest) {
        throw new InvalidArgumentException('checkout digest mismatch');
    }

    if (is_string($remoteHead) && $remoteHead !== '') {
        firewall_proof_require_sha($remoteHead);

        if ($remoteHead !== $candidate) {
            throw new InvalidArgumentException('candidate SHA mismatch');
        }
    }

    return [
        'candidate' => $candidate,
        'checkout_digest' => $remoteDigest,
        'target' => $target,
        'host' => $host,
        'instances' => [
            'operator' => $instances['operator'],
            'gateway' => $instances['gateway'],
            'dev' => $instances['dev'],
        ],
    ];
}

/**
 * @param  array<string, mixed>  $state
 * @return array{candidate: string, checkout_digest: string, target: string, host: string, instances: array{operator: string, gateway: string, dev: string}}
 */
function firewall_proof_validate_state(array $state, string $candidate): array
{
    $target = is_string($state['target'] ?? null) ? $state['target'] : '';
    $instances = is_array($state['instances'] ?? null) ? $state['instances'] : [];

    if (($state['candidate'] ?? '') !== $candidate) {
        throw new InvalidArgumentException('candidate SHA mismatch');
    }

    firewall_proof_require_sha($candidate);
    firewall_proof_assert_owned_instances($target, $instances);

    if (($state['host'] ?? '') !== FIREWALL_PROOF_HOST) {
        throw new InvalidArgumentException('target identity mismatch');
    }

    $digest = is_string($state['checkout_digest'] ?? null) ? $state['checkout_digest'] : '';
    firewall_proof_require_digest($digest);

    return [
        'candidate' => $candidate,
        'checkout_digest' => $digest,
        'target' => $target,
        'host' => FIREWALL_PROOF_HOST,
        'instances' => [
            'operator' => $instances['operator'],
            'gateway' => $instances['gateway'],
            'dev' => $instances['dev'],
        ],
    ];
}

function firewall_proof_assert_owned_target(string $owned, string $requested): void
{
    if ($owned !== $requested) {
        throw new InvalidArgumentException('cleanup identity mismatch');
    }
}

function firewall_proof_refuse_shared_topology_stop(): never
{
    throw new RuntimeException('shared topology stop refused');
}

/**
 * @param  array{candidate: string, target: string, expected: string, observed: string, result: string, evidence: string}  $fields
 */
function firewall_proof_receipt(array $fields): string
{
    return sprintf(
        '%s - candidate=%s; venue=retained-incus; environment=dev-fixture; target=%s; expected=%s; observed=%s; result=%s; evidence=`%s`',
        $fields['result'],
        $fields['candidate'],
        $fields['target'],
        $fields['expected'],
        $fields['observed'],
        $fields['result'],
        $fields['evidence'],
    );
}

/**
 * @return list<array{index: int, action: string, port: string, source: string, comment: string}>
 */
function firewall_proof_parse_ufw(string $output): array
{
    $rules = [];
    $lines = preg_split('/\R/', $output);

    foreach (is_array($lines) ? $lines : [] as $line) {
        $parsed = firewall_proof_parse_ufw_line(trim($line));

        if ($parsed !== null) {
            $rules[] = $parsed;
        }
    }

    return $rules;
}

/**
 * @return array{index: int, action: string, port: string, source: string, comment: string}|null
 */
function firewall_proof_parse_ufw_line(string $line): ?array
{
    $matches = [];

    if (
        preg_match(
            '/^\[\s*(\d+)\]\s+(\S+)\s+(ALLOW|DENY)\s+(IN|OUT)\s+(.+?)(?:\s+#\s*(.*))?$/',
            $line,
            $matches,
        ) !== 1
    ) {
        return null;
    }

    $target = trim(str_replace('(v6)', '', $matches[2]));
    $port = '';

    if (preg_match('/^(\d{1,5}(?::\d{1,5})?)\/(tcp|udp)$/', $target, $portMatches) === 1) {
        $port = $portMatches[1];
    }

    $source = trim(str_replace('(v6)', '', $matches[5]));

    return [
        'index' => (int) $matches[1],
        'action' => strtolower($matches[3]),
        'port' => $port,
        'source' => $source,
        'comment' => trim($matches[6] ?? ''),
    ];
}

/**
 * @param  list<array{index: int, action: string, port: string, source: string, comment: string}>  $rules
 * @return list<array{index: int, action: string, port: string, source: string, comment: string}>
 */
function firewall_proof_managed_port_rules(array $rules): array
{
    $managed = [];

    foreach ($rules as $rule) {
        if (
            $rule['port'] === FIREWALL_PROOF_PORT
            && $rule['comment'] === FIREWALL_PROOF_MANAGED_IDENTITY
        ) {
            $managed[] = $rule;
        }
    }

    return $managed;
}

/**
 * @param  list<array{index: int, action: string, port: string, source: string, comment: string}>  $rules
 */
function firewall_proof_assert_single_managed_source(array $rules, string $source): void
{
    $managed = firewall_proof_managed_port_rules($rules);

    if (count($managed) !== 1 || $managed[0]['source'] !== $source || $managed[0]['action'] !== 'allow') {
        throw new RuntimeException('failed-step=allow managed source mismatch');
    }
}

/**
 * @param  list<array{index: int, action: string, port: string, source: string, comment: string}>  $rules
 */
function firewall_proof_managed_allow_precedes_deny(array $rules): bool
{
    $managedIndexes = [];
    $denyIndex = null;

    foreach ($rules as $rule) {
        if (
            $rule['port'] === FIREWALL_PROOF_PORT
            && $rule['action'] === 'allow'
            && $rule['comment'] === FIREWALL_PROOF_MANAGED_IDENTITY
        ) {
            $managedIndexes[] = $rule['index'];
        }

        if ($rule['port'] === FIREWALL_PROOF_PORT && $rule['action'] === 'deny' && $rule['comment'] === '') {
            $denyIndex ??= $rule['index'];
        }
    }

    if ($managedIndexes === [] || $denyIndex === null) {
        return false;
    }

    foreach ($managedIndexes as $index) {
        if ($index >= $denyIndex) {
            return false;
        }
    }

    return true;
}

/**
 * @return list<string>
 */
function firewall_proof_seed_commands(): array
{
    $port = FIREWALL_PROOF_PORT;
    $protocol = FIREWALL_PROOF_PROTOCOL;
    $protected = FIREWALL_PROOF_PROTECTED_COMMENT;
    $managed = FIREWALL_PROOF_MANAGED_IDENTITY;
    $protectedSource = FIREWALL_PROOF_PROTECTED_SOURCE;
    $staleSource = FIREWALL_PROOF_STALE_SOURCE;

    return [
        "sudo ufw allow in on wg-orbit comment 'Orbit node security baseline permits SSH only through WireGuard.'",
        'sudo ufw --force enable',
        "sudo ufw allow from {$protectedSource} to any port {$port} proto {$protocol} comment '{$protected}'",
        "sudo ufw allow from {$staleSource} to any port {$port} proto {$protocol} comment '{$managed}'",
        "sudo ufw deny {$port}/{$protocol}",
    ];
}

/**
 * @param  list<array{index: int, action: string, port: string, source: string, comment: string}>  $rules
 * @return list<int>
 */
function firewall_proof_seed_cleanup_indexes(array $rules): array
{
    $indexes = [];

    foreach ($rules as $rule) {
        if ($rule['port'] !== FIREWALL_PROOF_PORT) {
            continue;
        }

        $ownedComment = $rule['comment'] === FIREWALL_PROOF_PROTECTED_COMMENT
            || $rule['comment'] === FIREWALL_PROOF_MANAGED_IDENTITY
            || ($rule['action'] === 'deny' && $rule['comment'] === '');

        if ($ownedComment) {
            $indexes[] = $rule['index'];
        }
    }

    rsort($indexes);

    return $indexes;
}

/**
 * @param  array<string, mixed>  $payload
 */
function firewall_proof_assert_allow_succeeded(array $payload): void
{
    if (! isset($payload['success'])) {
        throw new RuntimeException('failed-step=allow');
    }
}

/**
 * @param  array<string, mixed>  $report
 */
function firewall_proof_assert_doctor_healthy(array $report): void
{
    if (($report['healthy'] ?? false) !== true) {
        throw new RuntimeException('failed-step=doctor');
    }
}

/**
 * @param  list<string>  $command
 * @return array{exit: int, stdout: string, stderr: string}
 */
function firewall_proof_run_command(array $command, string $cwd, int $timeout): array
{
    $process = new Process($command, $cwd);
    $process->setTimeout($timeout);
    $process->run();

    return [
        'exit' => $process->getExitCode() ?? 1,
        'stdout' => $process->getOutput(),
        'stderr' => $process->getErrorOutput(),
    ];
}
