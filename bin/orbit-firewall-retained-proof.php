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
const FIREWALL_PROOF_BINDING = 'synced-tracked-tree';
const FIREWALL_PROOF_TRANSPORT_CHECKOUT = '/home/orbit/orbit';

/**
 * @return list<string>
 */
function firewall_proof_synced_exclude_patterns(): array
{
    return [
        '.git',
        '.worktrees',
        '.codex',
        '.cursor',
        '.idea',
        '.nova',
        '.orbit',
        '.orbit-e2e-vendor-archives',
        '.orbit-e2e-source-sync.lock',
        '.phpunit.cache',
        '.vscode',
        '.zed',
        '.env',
        '.env.e2e',
        'auth.json',
        'build',
        'tmp-e2e-archive-manifest-*.txt',
        'tmp-e2e-tree-hash-*',
        'bin/orbit-binary-*',
        'apps/gateway/database/*.sqlite',
        'apps/gateway/database/*.sqlite-*',
        'node_modules',
        'apps/gateway/.env',
        'apps/gateway/.env.e2e',
        'apps/gateway/.env.local',
        'apps/cli/.env',
        'apps/cli/.env.e2e',
        'apps/cli/.env.local',
        'apps/gateway/public/build',
        'apps/gateway/public/hot',
        'apps/gateway/public/storage',
        'apps/gateway/storage/framework/e2e/*',
        'apps/gateway/storage/app/orbit/ca/*',
        'apps/gateway/storage/app/orbit/certs/*',
        'apps/gateway/storage/app/orbit/keys/*',
        'apps/gateway/storage/framework/cache/data/*',
        'apps/gateway/storage/framework/sessions/*',
        'apps/gateway/storage/framework/ssh-known-hosts/*',
        'apps/gateway/storage/framework/testing/*',
        'apps/gateway/storage/framework/views/*',
        'apps/gateway/storage/logs/*',
        'apps/gateway/storage/pail',
        'apps/gateway/tests/E2E/.docker-feature-tests/*',
        'apps/gateway/tests/E2E/.incus-feature-tests/*',
        'apps/agent/target',
        'apps/gateway/vendor',
        'apps/cli/vendor',
        'apps/docs/vendor',
        'apps/macos/target',
        'apps/e2e/vendor',
        'packages/core/vendor',
        'packages/sdk/vendor',
        'vendor',
    ];
}

function firewall_proof_normalize_path(string $path): string
{
    $path = str_replace('\\', '/', $path);

    return str_starts_with($path, './') ? substr($path, 2) : ltrim($path, '/');
}

function firewall_proof_path_excluded(string $path): bool
{
    $path = firewall_proof_normalize_path($path);

    if ($path === '') {
        return true;
    }

    foreach (firewall_proof_synced_exclude_patterns() as $pattern) {
        $pattern = firewall_proof_normalize_path($pattern);

        if ($path === $pattern || str_starts_with($path, $pattern.'/')) {
            return true;
        }

        if (fnmatch($pattern, $path)) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function firewall_proof_list_synced_paths(string $root): array
{
    $output = (string) shell_exec(
        'git -C '.escapeshellarg($root).' ls-files -z --full-name --cached 2>/dev/null',
    );
    $paths = [];

    foreach (explode("\0", $output) as $path) {
        $path = firewall_proof_normalize_path($path);

        if ($path === '' || firewall_proof_path_excluded($path) || ! is_file($root.'/'.$path)) {
            continue;
        }

        $paths[] = $path;
    }

    $paths = array_values(array_unique($paths));
    sort($paths, SORT_STRING);

    return $paths;
}

/**
 * @param  list<string>  $paths
 * @return array<string, string>
 */
function firewall_proof_hash_paths(string $root, array $paths): array
{
    $files = [];

    foreach ($paths as $relative) {
        $digest = hash_file('sha256', rtrim($root, '/').'/'.$relative);

        if (! is_string($digest)) {
            throw new InvalidArgumentException('checkout digest mismatch');
        }

        $files[$relative] = $digest;
    }

    return $files;
}

/**
 * @param  array<string, string>  $files
 */
function firewall_proof_tree_digest(array $files): string
{
    ksort($files, SORT_STRING);
    $parts = [];

    foreach ($files as $path => $digest) {
        $parts[] = $path.':'.$digest;
    }

    return hash('sha256', implode("\n", $parts));
}

/**
 * @return array<string, string>
 */
function firewall_proof_local_synced_files(string $root): array
{
    return firewall_proof_hash_paths($root, firewall_proof_list_synced_paths($root));
}

function firewall_proof_digest_checkout(string $root): string
{
    return firewall_proof_tree_digest(firewall_proof_local_synced_files($root));
}

/**
 * @param  array<string, string>  $remote
 * @param  list<string>  $extraPaths
 * @return array<string, string>
 */
function firewall_proof_include_unexplained_extras(array $remote, array $extraPaths, string $localRoot): array
{
    foreach ($extraPaths as $path) {
        $path = firewall_proof_normalize_path($path);

        if ($path === '' || isset($remote[$path]) || is_file($localRoot.'/'.$path)) {
            continue;
        }

        $remote[$path] = '';
    }

    return $remote;
}

/**
 * @return array{files: array<string, string>, extras: list<string>}
 */
function firewall_proof_parse_remote_tree_output(string $output): array
{
    $files = [];
    $extras = [];

    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        $line = rtrim($line, "\r\n");

        if ($line === '') {
            continue;
        }

        $parts = explode("\t", $line);
        $kind = $parts[0] ?? '';

        if ($kind === 'H' && isset($parts[1], $parts[2]) && preg_match('/^[a-f0-9]{64}$/', $parts[2]) === 1) {
            $path = firewall_proof_normalize_path($parts[1]);

            if ($path !== '') {
                $files[$path] = $parts[2];
            }

            continue;
        }

        if ($kind === 'E' && isset($parts[1])) {
            $path = firewall_proof_normalize_path($parts[1]);

            if ($path !== '') {
                $extras[] = $path;
            }
        }
    }

    return [
        'files' => $files,
        'extras' => $extras,
    ];
}

/**
 * @param  array<string, string>  $local
 * @param  array<string, string>  $remote
 */
function firewall_proof_assert_synced_trees(array $local, array $remote): void
{
    $missing = array_diff_key($local, $remote);
    $extra = array_diff_key($remote, $local);

    if ($missing !== [] || $extra !== []) {
        throw new InvalidArgumentException('checkout digest mismatch');
    }

    foreach ($local as $path => $digest) {
        if ($remote[$path] !== $digest) {
            throw new InvalidArgumentException('checkout digest mismatch');
        }
    }
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
 * @return array{candidate: string, binding: string, checkout_digest: string, target: string, host: string, instances: array{operator: string, gateway: string, dev: string}}
 */
function firewall_proof_bind(array $observed): array
{
    $candidate = is_string($observed['candidate'] ?? null) ? $observed['candidate'] : '';
    $localDigest = is_string($observed['local_checkout_digest'] ?? null) ? $observed['local_checkout_digest'] : '';
    $remoteDigest = is_string($observed['remote_checkout_digest'] ?? null) ? $observed['remote_checkout_digest'] : '';
    $target = is_string($observed['target'] ?? null) ? $observed['target'] : '';
    $host = is_string($observed['host'] ?? null) ? strtolower($observed['host']) : '';
    $instances = is_array($observed['instances'] ?? null) ? $observed['instances'] : [];

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

    return [
        'candidate' => $candidate,
        'binding' => FIREWALL_PROOF_BINDING,
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
 * @return array{candidate: string, binding: string, checkout_digest: string, target: string, host: string, instances: array{operator: string, gateway: string, dev: string}}
 */
function firewall_proof_validate_state(array $state, string $candidate): array
{
    $target = is_string($state['target'] ?? null) ? $state['target'] : '';
    $instances = is_array($state['instances'] ?? null) ? $state['instances'] : [];

    if (($state['candidate'] ?? '') !== $candidate) {
        throw new InvalidArgumentException('candidate SHA mismatch');
    }

    if (($state['binding'] ?? '') !== FIREWALL_PROOF_BINDING) {
        throw new InvalidArgumentException('checkout digest mismatch');
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
        'binding' => FIREWALL_PROOF_BINDING,
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
 * @param  array{candidate: string, target: string, binding: string, checkout_digest: string, expected: string, observed: string, result: string, evidence: string}  $fields
 */
function firewall_proof_receipt(array $fields): string
{
    return sprintf(
        '%s - candidate=%s; venue=retained-incus; environment=dev-fixture; target=%s; binding=%s; checkout_digest=%s; expected=%s; observed=%s; result=%s; evidence=`%s`',
        $fields['result'],
        $fields['candidate'],
        $fields['target'],
        $fields['binding'],
        $fields['checkout_digest'],
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
function firewall_proof_run_command(array $command, string $cwd, int $timeout, ?string $input = null): array
{
    $process = new Process($command, $cwd);
    $process->setTimeout($timeout);

    if ($input !== null) {
        $process->setInput($input);
    }

    $process->run();

    return [
        'exit' => $process->getExitCode() ?? 1,
        'stdout' => $process->getOutput(),
        'stderr' => $process->getErrorOutput(),
    ];
}
