<?php

declare(strict_types=1);

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Tools\ToolsFixer;
use App\Services\Tools\ToolsProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function phpCliDoctorTool(string $variant = 'coverage'): NodeTool
{
    $node = Node::factory()->create([
        'status' => NodeStatus::Active,
        'platform' => 'ubuntu-26.04',
    ]);

    return NodeTool::query()->create([
        'node_id' => $node->id,
        'name' => 'php-cli',
        'expected_state' => 'installed',
        'config' => ['variant' => $variant],
    ]);
}

/**
 * @return array<string, array<string, mixed>>
 */
function phpCliCompleteStandardMinors(): array
{
    $minors = [];

    foreach (['8.5' => '8.5.8', '8.4' => '8.4.21', '8.3' => '8.3.31'] as $minor => $patch) {
        $minors[$minor] = [
            'present' => true,
            'patch' => $patch,
            'expected_patch' => $patch,
            'extension_loaded_pcov' => false,
            'function_exists_pcov_start' => false,
            'pcov_enabled' => false,
            'ri_pcov_ok' => false,
            'classification' => 'standard',
            'ok' => true,
            'summary' => 'ok',
        ];
    }

    return $minors;
}

/**
 * @return array<string, array<string, mixed>>
 */
function phpCliCompleteCoverageMinors(bool $brokenEightFive = false): array
{
    $minors = [];

    foreach (['8.5' => '8.5.8', '8.4' => '8.4.21', '8.3' => '8.3.31'] as $minor => $patch) {
        $broken = $brokenEightFive && $minor === '8.5';
        $minors[$minor] = [
            'present' => true,
            'patch' => $patch,
            'expected_patch' => $patch,
            'extension_loaded_pcov' => ! $broken,
            'function_exists_pcov_start' => ! $broken,
            'pcov_enabled' => ! $broken,
            'ri_pcov_ok' => ! $broken,
            'classification' => $broken ? 'coverage_broken' : 'coverage',
            'ok' => ! $broken,
            'summary' => $broken ? 'broken' : 'ok',
        ];
    }

    return $minors;
}

it('does not emit false coverage drift under compatibility when standard runtime is healthy', function (): void {
    $tool = phpCliDoctorTool('coverage');
    $probe = new ToolsProbe;
    $method = new ReflectionMethod($probe, 'checkPhpCliRuntimeState');
    $method->setAccessible(true);

    $snapshot = new ProbeSnapshot([
        'php-cli' => [
            'installed' => true,
            'variant' => 'coverage',
            'desired_variant' => 'coverage',
            'effective_variant' => 'standard',
            'install_contract' => 'compatibility',
            'matrix_cutover_pending' => true,
            'minors' => phpCliCompleteStandardMinors(),
            'php_cli_probe_ok' => true,
            'php_cli_minors_complete' => true,
        ],
    ]);

    /** @var list<DriftEntry> $issues */
    $issues = $method->invoke($probe, $tool, $snapshot);
    $diff = $probe->diff($tool, $snapshot, allowProvisioning: true);

    expect($issues)
        ->toBeEmpty()
        ->and(collect($diff)->where(
            fn (DriftEntry $entry): bool => $entry->key === 'tool.php_cli_coverage_missing',
        ))
        ->toHaveCount(0);
});

it('exposes desired coverage and pending matrix cutover in compatibility probe evidence', function (): void {
    $node = Node::factory()
        ->appDev()
        ->create([
            'status' => NodeStatus::Active,
            'platform' => 'ubuntu-26.04',
        ]);
    $tool = NodeTool::query()->create([
        'node_id' => $node->id,
        'name' => 'php-cli',
        'expected_state' => 'installed',
        'config' => ['variant' => 'coverage'],
    ]);

    $probe = new ToolsProbe;
    $method = new ReflectionMethod($probe, 'checkPhpCliRuntimeState');
    $method->setAccessible(true);

    // Incomplete probe still carries desired/effective/cutover evidence.
    $snapshot = new ProbeSnapshot([
        'php-cli' => [
            'installed' => false,
            'variant' => 'coverage',
            'desired_variant' => 'coverage',
            'effective_variant' => 'standard',
            'install_contract' => 'compatibility',
            'matrix_cutover_pending' => true,
            'minors' => [],
            'php_cli_probe_ok' => false,
            'php_cli_minors_complete' => false,
        ],
    ]);

    /** @var list<DriftEntry> $issues */
    $issues = $method->invoke($probe, $tool, $snapshot);

    expect($issues)
        ->toHaveCount(1)
        ->and($issues[0]->key)
        ->toBe('tool.capability_missing')
        ->and($issues[0]->detail['desired_variant'] ?? null)
        ->toBe('coverage')
        ->and($issues[0]->detail['effective_variant'] ?? null)
        ->toBe('standard')
        ->and($issues[0]->detail['install_contract'] ?? null)
        ->toBe('compatibility')
        ->and($issues[0]->detail['matrix_cutover_pending'] ?? null)
        ->toBeTrue()
        ->and($issues[0]->detail['variant'] ?? null)
        ->toBe('coverage');
});

it('does not emit coverage_missing under matrix when desired coverage is healthy', function (): void {
    $tool = phpCliDoctorTool('coverage');
    $probe = new ToolsProbe;
    $method = new ReflectionMethod($probe, 'checkPhpCliRuntimeState');
    $method->setAccessible(true);

    $snapshot = new ProbeSnapshot([
        'php-cli' => [
            'installed' => true,
            'variant' => 'coverage',
            'desired_variant' => 'coverage',
            'effective_variant' => 'coverage',
            'install_contract' => 'matrix',
            'matrix_cutover_pending' => false,
            'minors' => phpCliCompleteCoverageMinors(brokenEightFive: false),
            'php_cli_probe_ok' => true,
            'php_cli_minors_complete' => true,
        ],
    ]);

    $issues = $method->invoke($probe, $tool, $snapshot);
    $fixer = new ToolsFixer;
    $repair = new ReflectionMethod($fixer, 'repairCommand');
    $repair->setAccessible(true);

    // Healthy coverage matrix runtime: no coverage_missing drift.
    expect($issues)->toBeEmpty();

    // After matrix cutover, a real coverage_missing repair installs variant-named
    // coverage artifacts (not bulk / non-variant filenames).
    $stale = new DriftEntry(
        family: 'tool',
        key: 'tool.php_cli_coverage_missing',
        kind: DriftKind::Divergent,
        summary: 'coverage missing',
    );
    $command = $repair->invoke($fixer, $tool, $stale);

    expect($command)
        ->toBeString()
        ->toContain('php-8.5.8-cli-coverage-')
        ->toContain('/opt/orbit/php')
        ->not->toContain('dl.static-php.dev/static-php-cli/bulk');
});

it('emits tool.php_cli_coverage_missing after matrix promotion when PCOV is broken', function (): void {
    $tool = phpCliDoctorTool('coverage');
    $probe = new ToolsProbe;
    $method = new ReflectionMethod($probe, 'checkPhpCliRuntimeState');
    $method->setAccessible(true);

    $snapshot = new ProbeSnapshot([
        'php-cli' => [
            'installed' => true,
            'variant' => 'coverage',
            'desired_variant' => 'coverage',
            'effective_variant' => 'coverage',
            'install_contract' => 'matrix',
            'matrix_cutover_pending' => false,
            'minors' => phpCliCompleteCoverageMinors(brokenEightFive: true),
        ],
    ]);

    /** @var list<DriftEntry> $issues */
    $issues = $method->invoke($probe, $tool, $snapshot);

    expect($issues)
        ->toHaveCount(1)
        ->and($issues[0]->key)
        ->toBe('tool.php_cli_coverage_missing')
        ->and($issues[0]->kind)
        ->toBe(DriftKind::Divergent)
        ->and($issues[0]->detail['minor'] ?? null)
        ->toBe('8.5')
        ->and($issues[0]->detail['desired_variant'] ?? null)
        ->toBe('coverage')
        ->and($issues[0]->detail['effective_variant'] ?? null)
        ->toBe('coverage')
        ->and($issues[0]->detail['install_contract'] ?? null)
        ->toBe('matrix')
        ->and($issues[0]->detail['matrix_cutover_pending'] ?? null)
        ->toBeFalse();
});

it('emits tool.php_cli_coverage_missing when coverage PCOV is broken under effective coverage', function (): void {
    $tool = phpCliDoctorTool('coverage');
    $probe = new ToolsProbe;
    $method = new ReflectionMethod($probe, 'checkPhpCliRuntimeState');
    $method->setAccessible(true);

    $snapshot = new ProbeSnapshot([
        'php-cli' => [
            'installed' => true,
            'variant' => 'coverage',
            'desired_variant' => 'coverage',
            'effective_variant' => 'coverage',
            'install_contract' => 'matrix',
            'matrix_cutover_pending' => false,
            'minors' => [
                '8.5' => [
                    'present' => true,
                    'patch' => '8.5.8',
                    'expected_patch' => '8.5.8',
                    'extension_loaded_pcov' => false,
                    'function_exists_pcov_start' => false,
                    'pcov_enabled' => false,
                    'ri_pcov_ok' => false,
                    'classification' => 'coverage_broken',
                    'ok' => false,
                    'summary' => 'broken',
                ],
                '8.4' => [
                    'present' => true,
                    'patch' => '8.4.21',
                    'expected_patch' => '8.4.21',
                    'extension_loaded_pcov' => true,
                    'function_exists_pcov_start' => true,
                    'pcov_enabled' => true,
                    'ri_pcov_ok' => true,
                    'classification' => 'coverage',
                    'ok' => true,
                    'summary' => 'ok',
                ],
                '8.3' => [
                    'present' => true,
                    'patch' => '8.3.31',
                    'expected_patch' => '8.3.31',
                    'extension_loaded_pcov' => true,
                    'function_exists_pcov_start' => true,
                    'pcov_enabled' => true,
                    'ri_pcov_ok' => true,
                    'classification' => 'coverage',
                    'ok' => true,
                    'summary' => 'ok',
                ],
            ],
        ],
    ]);

    /** @var list<DriftEntry> $issues */
    $issues = $method->invoke($probe, $tool, $snapshot);

    expect($issues)
        ->toHaveCount(1)
        ->and($issues[0]->key)
        ->toBe('tool.php_cli_coverage_missing')
        ->and($issues[0]->kind)
        ->toBe(DriftKind::Divergent)
        ->and($issues[0]->detail['minor'] ?? null)
        ->toBe('8.5');
});

it('emits capability_missing for a missing minor without collapsing coverage drift', function (): void {
    $tool = phpCliDoctorTool('coverage');
    $probe = new ToolsProbe;
    $method = new ReflectionMethod($probe, 'checkPhpCliRuntimeState');
    $method->setAccessible(true);

    $snapshot = new ProbeSnapshot([
        'php-cli' => [
            'installed' => true,
            'variant' => 'coverage',
            'minors' => [
                '8.5' => [
                    'present' => false,
                    'patch' => null,
                    'expected_patch' => '8.5.8',
                    'classification' => 'missing',
                    'ok' => false,
                ],
            ],
        ],
    ]);

    /** @var list<DriftEntry> $issues */
    $issues = $method->invoke($probe, $tool, $snapshot);

    expect($issues)->toHaveCount(1)->and($issues[0]->key)->toBe('tool.capability_missing');
});

it('restores coverage drift through the install path using stored variant', function (): void {
    $tool = phpCliDoctorTool('coverage');
    $fixer = new ToolsFixer;
    $method = new ReflectionMethod($fixer, 'repairCommand');
    $method->setAccessible(true);

    $entry = new DriftEntry(
        family: 'tool',
        key: 'tool.php_cli_coverage_missing',
        kind: DriftKind::Divergent,
        summary: 'coverage missing',
    );

    $command = $method->invoke($fixer, $tool, $entry);

    // Restore uses stored/role variant config; under the compatibility contract the
    // install path remains the currently published Orbit/bulk artifacts.
    expect($command)
        ->toBeString()
        ->toContain('php-8.5.8-cli-')
        ->toContain('/opt/orbit/php');
});

it('reports capability_missing for empty minors after a failed or silent probe', function (): void {
    $tool = phpCliDoctorTool('coverage');
    $probe = new ToolsProbe;

    $snapshot = new ProbeSnapshot([
        'php-cli' => [
            'installed' => false,
            'variant' => 'coverage',
            'minors' => [],
            'php_cli_probe_ok' => false,
            'php_cli_minors_complete' => false,
        ],
    ]);

    $runtimeIssues = new ReflectionMethod($probe, 'checkPhpCliRuntimeState')
        ->invoke($probe, $tool, $snapshot);
    $capabilityIssues = new ReflectionMethod($probe, 'checkCapabilityPresence')
        ->invoke($probe, $tool, $snapshot);
    $diff = $probe->diff($tool, $snapshot, allowProvisioning: true);

    expect($runtimeIssues)
        ->toHaveCount(1)
        ->and($runtimeIssues[0]->key)
        ->toBe('tool.capability_missing')
        ->and($runtimeIssues[0]->detail['reason'] ?? null)
        ->toBe('probe_incomplete')
        ->and($capabilityIssues)
        ->toBeEmpty()
        ->and(collect($diff)->where(fn (DriftEntry $entry): bool => $entry->key === 'tool.capability_missing'))
        ->toHaveCount(1);
});

it('reports capability_missing for malformed incomplete per-minor probe output', function (): void {
    $tool = phpCliDoctorTool('standard');
    $probe = new ToolsProbe;

    // Only one minor parsed (malformed/truncated probe). Must not look healthy.
    $snapshot = new ProbeSnapshot([
        'php-cli' => [
            'installed' => true,
            'variant' => 'standard',
            'minors' => [
                '8.5' => [
                    'present' => true,
                    'patch' => '8.5.8',
                    'expected_patch' => '8.5.8',
                    'classification' => 'standard',
                    'ok' => true,
                ],
            ],
            'php_cli_probe_ok' => false,
            'php_cli_minors_complete' => false,
        ],
    ]);

    $runtimeIssues = new ReflectionMethod($probe, 'checkPhpCliRuntimeState')
        ->invoke($probe, $tool, $snapshot);
    $capabilityIssues = new ReflectionMethod($probe, 'checkCapabilityPresence')
        ->invoke($probe, $tool, $snapshot);
    $diff = $probe->diff($tool, $snapshot, allowProvisioning: true);

    expect($runtimeIssues)
        ->toHaveCount(1)
        ->and($runtimeIssues[0]->key)
        ->toBe('tool.capability_missing')
        ->and($runtimeIssues[0]->detail['reason'] ?? null)
        ->toBe('probe_incomplete')
        ->and($runtimeIssues[0]->detail['observed_minors'] ?? null)
        ->toBe(['8.5'])
        ->and($capabilityIssues)
        ->toBeEmpty()
        ->and(collect($diff)->first(
            fn (DriftEntry $entry): bool => (
                $entry->key === 'tool.capability_missing'
                && ($entry->detail['reason'] ?? null) === 'probe_incomplete'
            ),
        ))
        ->not->toBeNull();
});

it('still suppresses generic capability drift when the per-minor snapshot is complete', function (): void {
    $tool = phpCliDoctorTool('standard');
    $probe = new ToolsProbe;

    $completeMinors = [];
    foreach (['8.5' => '8.5.8', '8.4' => '8.4.21', '8.3' => '8.3.31'] as $minor => $patch) {
        $completeMinors[$minor] = [
            'present' => true,
            'patch' => $patch,
            'expected_patch' => $patch,
            'extension_loaded_pcov' => false,
            'function_exists_pcov_start' => false,
            'pcov_enabled' => false,
            'ri_pcov_ok' => false,
            'classification' => 'standard',
            'ok' => true,
        ];
    }

    $snapshot = new ProbeSnapshot([
        'php-cli' => [
            'installed' => true,
            'variant' => 'standard',
            'minors' => $completeMinors,
            'php_cli_probe_ok' => true,
            'php_cli_minors_complete' => true,
        ],
    ]);

    $capabilityIssues = new ReflectionMethod($probe, 'checkCapabilityPresence')
        ->invoke($probe, $tool, $snapshot);
    $runtimeIssues = new ReflectionMethod($probe, 'checkPhpCliRuntimeState')
        ->invoke($probe, $tool, $snapshot);

    expect($capabilityIssues)->toBeEmpty()->and($runtimeIssues)->toBeEmpty();
});
