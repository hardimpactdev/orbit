<?php

declare(strict_types=1);

use App\E2E\Support\E2ECommand;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EImage;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2EProvisioningBundle;
use App\E2E\Support\E2ERun;
use App\E2E\Support\IncusProvider;
use App\E2E\Support\ProviderPool;

pest()->group('e2e-provision');

it('provisions app nodes with the security baseline and blocks production workspaces', function (): void {
    $config = E2EConfig::fromEnvironment();
    $provider = new IncusProvider($config);
    $selection = (new ProviderPool([$provider]))->select(E2EImage::Blank);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $run = E2ERun::start($provider, 'app-node-security-baseline');
    $bundle = null;
    $passed = false;

    try {
        $bundle = E2EProvisioningBundle::stage($provider);
        $key = $run->createSshKeyPair();
        $control = e2eProvisionControlFromBlank($provider, $run, $bundle, $config, $key);
        [$gateway] = e2eProvisionGatewayThroughNodeNew($provider, $run, $config, $control, $key);
        [$dev] = e2eProvisionAppThroughNodeNew($provider, $run, $config, $control, $key, 'app-dev-1', 'development', 'test');
        [$prod] = e2eProvisionAppThroughNodeNew($provider, $run, $config, $control, $key, 'app-prod-1', 'production');

        assertAppNodeSecurityFiles($dev);
        assertAppNodeSecurityFiles($prod);

        prepareAppRuntimeSecurityTargets($dev, $prod);
        gatewayJson($gateway, <<<'PHP'
$nodes = \App\Models\Node::query()
    ->whereIn('name', ['app-dev-1', 'app-prod-1'])
    ->pluck('id', 'name');

$prod = \App\Models\App::query()->updateOrCreate(
    ['name' => 'prod'],
    [
        'node_id' => $nodes->get('app-prod-1'),
        'environment' => 'production',
        'domain' => 'prod.example.test',
        'path' => '/home/orbit/apps/prod',
        'document_root' => 'public',
        'php_version' => '8.5',
        'adopted' => false,
    ],
);
$docs = \App\Models\App::query()->updateOrCreate(
    ['name' => 'docs'],
    [
        'node_id' => $nodes->get('app-dev-1'),
        'environment' => 'development',
        'path' => '/home/orbit/apps/docs',
        'document_root' => 'public',
        'php_version' => '8.5',
        'adopted' => false,
    ],
);
\App\Models\Workspace::query()->updateOrCreate(
    ['app_id' => $docs->id, 'name' => 'feature-sec'],
    [
        'path' => '/home/orbit/apps/docs/.worktrees/feature-sec',
        'php_version' => null,
        'lifecycle_status' => \App\Enums\WorkspaceLifecycleStatus::Expected->value,
    ],
);

echo json_encode([
    'prod_app' => $prod->id,
    'workspace_app' => $docs->id,
], JSON_THROW_ON_ERROR);
PHP);

        $appDoctor = E2ECommand::ssh(
            $gateway,
            'orbit',
            $key,
            'cd /home/orbit/orbit && php artisan doctor --node=app-prod-1 --family=app --restore --json',
            timeoutSeconds: 240,
        );
        $appDoctorPayload = json_decode(trim($appDoctor->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($appDoctorPayload['success']['data']['doctor']['healthy'])->toBeTrue();
        assertProductionAppRuntimeSecurity($prod);

        $workspaceDoctor = E2ECommand::ssh(
            $gateway,
            'orbit',
            $key,
            'cd /home/orbit/orbit && php artisan doctor --node=app-dev-1 --family=workspace --restore --json',
            timeoutSeconds: 240,
        );
        $workspaceDoctorPayload = json_decode(trim($workspaceDoctor->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($workspaceDoctorPayload['success']['data']['doctor']['healthy'])->toBeTrue();
        assertDevelopmentWorkspaceRuntimeSecurity($dev);

        $registry = gatewayJson($gateway, <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-prod-1')->firstOrFail();
$rules = \App\Models\FirewallRule::query()
    ->where('node_id', $node->id)
    ->where('owner', 'node-security')
    ->where('protected', true)
    ->where('action', 'deny')
    ->where('port', '22')
    ->pluck('address_family')
    ->sort()
    ->values()
    ->all();

echo json_encode([
    'user' => $node->user,
    'host_key_type' => $node->host_key_type,
    'host_key_fingerprint' => $node->host_key_fingerprint,
    'host_key_pin_mode' => $node->host_key_pin_mode,
    'public_ssh_deny_families' => $rules,
], JSON_THROW_ON_ERROR);
PHP);

        expect($registry)->toMatchArray([
            'user' => 'orbit',
            'public_ssh_deny_families' => ['v4', 'v6'],
        ]);
        expect($registry['host_key_type'])->not->toBeEmpty()
            ->and($registry['host_key_fingerprint'])->not->toBeEmpty()
            ->and($registry['host_key_pin_mode'])->not->toBeEmpty();

        $doctor = E2ECommand::ssh(
            $gateway,
            'orbit',
            $key,
            'cd /home/orbit/orbit && php artisan doctor --node=app-prod-1 --family=node --json',
            timeoutSeconds: 180,
        );
        $doctorPayload = json_decode(trim($doctor->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $securityIssues = collect($doctorPayload['success']['data']['doctor']['issues'] ?? [])
            ->filter(fn (array $issue): bool => str_starts_with((string) ($issue['key'] ?? ''), 'node.security.'))
            ->values()
            ->all();

        expect($securityIssues)->toBe([]);

        $workspace = E2ECommand::ssh(
            $gateway,
            'orbit',
            $key,
            'cd /home/orbit/orbit && php artisan workspace:new blocked --app=prod --json || true',
            timeoutSeconds: 120,
        );
        $workspacePayload = json_decode(trim($workspace->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($workspacePayload['error']['code'])->toBe('workspace.unsupported_for_production');

        $passed = true;
    } finally {
        e2eProvisionCleanup($passed, run: $run);
        $bundle?->cleanup();
    }
});

function assertAppNodeSecurityFiles(E2EInstance $instance): void
{
    E2ECommand::exec(
        $instance,
        <<<'SH'
set -euo pipefail
test "$(stat -c '%U:%G %a' /home/orbit)" = "orbit:orbit 700"
test -f /etc/ssh/sshd_config.d/99-orbit-hardening.conf
grep -q "PasswordAuthentication no" /etc/ssh/sshd_config.d/99-orbit-hardening.conf
grep -q "AllowUsers orbit" /etc/ssh/sshd_config.d/99-orbit-hardening.conf
test -f /etc/apt/apt.conf.d/20auto-upgrades
test -f /etc/apt/apt.conf.d/50unattended-upgrades
test -f /etc/sysctl.d/60-orbit.conf
sudo ufw status | grep -E "22/tcp.*DENY" >/dev/null
SH,
        'App node security baseline files were not installed.',
        timeoutSeconds: 120,
    );
}

function prepareAppRuntimeSecurityTargets(E2EInstance $dev, E2EInstance $prod): void
{
    E2ECommand::exec(
        $prod,
        <<<'SH'
set -euo pipefail
sudo install -d -m 0755 -o orbit -g orbit /home/orbit/apps/prod/public /home/orbit/apps/prod/storage /home/orbit/apps/prod/bootstrap/cache
SH,
        'Production app runtime target could not be prepared.',
        timeoutSeconds: 120,
    );

    E2ECommand::exec(
        $dev,
        <<<'SH'
set -euo pipefail
sudo install -d -m 0755 -o orbit -g orbit /home/orbit/apps/docs/public /home/orbit/apps/docs/storage /home/orbit/apps/docs/bootstrap/cache
sudo install -d -m 0755 -o orbit -g orbit /home/orbit/apps/docs/.worktrees/feature-sec/public /home/orbit/apps/docs/.worktrees/feature-sec/storage /home/orbit/apps/docs/.worktrees/feature-sec/bootstrap/cache
SH,
        'Development workspace runtime target could not be prepared.',
        timeoutSeconds: 120,
    );
}

function assertProductionAppRuntimeSecurity(E2EInstance $prod): void
{
    E2ECommand::exec(
        $prod,
        <<<'SH'
set -euo pipefail
runtime_user="$(getent passwd | cut -d: -f1 | grep '^orbit-prod-' | head -n1)"
test -n "$runtime_user"
test "$(stat -c '%U' /home/orbit/apps/prod)" = "$runtime_user"
grep -q "user = $runtime_user" /etc/php/8.5/fpm/pool.d/orbit-prod.conf
grep -q "clear_env = yes" /etc/php/8.5/fpm/pool.d/orbit-prod.conf
grep -q "php_admin_value\[open_basedir\]" /etc/php/8.5/fpm/pool.d/orbit-prod.conf
grep -q "php_admin_value\[disable_functions\]" /etc/php/8.5/fpm/pool.d/orbit-prod.conf
test -f /etc/systemd/system/php8.5-fpm.service.d/10-orbit-hardening.conf
grep -q "ProtectSystem=strict" /etc/systemd/system/php8.5-fpm.service.d/10-orbit-hardening.conf
grep -q "/home/orbit/apps/prod" /etc/systemd/system/php8.5-fpm.service.d/10-orbit-hardening.conf
SH,
        'Production app runtime security was not restored.',
        timeoutSeconds: 120,
    );
}

function assertDevelopmentWorkspaceRuntimeSecurity(E2EInstance $dev): void
{
    E2ECommand::exec(
        $dev,
        <<<'SH'
set -euo pipefail
runtime_user="$(getent passwd | cut -d: -f1 | grep '^orbit-ws-docs-feature-sec' | head -n1)"
test -n "$runtime_user"
test "$(stat -c '%U' /home/orbit/apps/docs/.worktrees/feature-sec)" = "$runtime_user"
grep -q "user = $runtime_user" /etc/php/8.5/fpm/pool.d/orbit-docs-feature-sec.conf
grep -q "clear_env = yes" /etc/php/8.5/fpm/pool.d/orbit-docs-feature-sec.conf
grep -q "php_admin_value\[open_basedir\]" /etc/php/8.5/fpm/pool.d/orbit-docs-feature-sec.conf
grep -q "php_admin_value\[disable_functions\]" /etc/php/8.5/fpm/pool.d/orbit-docs-feature-sec.conf
test -f /etc/systemd/system/php8.5-fpm.service.d/10-orbit-hardening.conf
grep -q "ProtectSystem=strict" /etc/systemd/system/php8.5-fpm.service.d/10-orbit-hardening.conf
grep -q "/home/orbit/apps/docs/.worktrees/feature-sec" /etc/systemd/system/php8.5-fpm.service.d/10-orbit-hardening.conf
SH,
        'Development workspace runtime security was not restored.',
        timeoutSeconds: 120,
    );
}

/**
 * @return array<string, mixed>
 */
function gatewayJson(E2EInstance $gateway, string $php): array
{
    $execute = 'eval(base64_decode('.var_export(base64_encode($php), true).'));';

    $result = E2ECommand::orbit(
        $gateway,
        'cd /home/orbit/orbit && php artisan tinker --execute='.escapeshellarg($execute),
        'Gateway JSON command failed.',
        timeoutSeconds: 120,
    );

    return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}
