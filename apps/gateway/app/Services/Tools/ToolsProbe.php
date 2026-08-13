<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Data\Convergence\ManagedFileDriftSignals;
use App\Data\Convergence\ManagedFilePlan;
use App\Data\Convergence\ManagedFileProbe;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\Convergence\ConvergenceStatus;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Convergence\ManagedFile;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Tools\PhpCliTool;
use App\Tools\UserScopedCliTool;
use App\Tools\UserScopedCliUsers;
use InvalidArgumentException;
use JsonException;
use Orbit\Core\Php\PhpCliArtifactCatalog;
use Orbit\Core\Php\PhpCliRuntimeClassifier;
use Orbit\Core\Php\PhpCliVariant;
use Throwable;

final readonly class ToolsProbe
{
    private const array ExpectedStates = ['installed', 'absent'];

    public function __construct(
        private ?ToolCatalog $catalog = null,
        private ?RunsInternalCommands $localExecutor = null,
        private ?ToolScriptDispatcher $scripts = null,
        private ?AgentToolConsumerUrlProbe $agentConsumerUrlProbe = null,
        private ?ToolCapabilityProbeContract $capabilityProbeContract = null,
    ) {}

    public function key(): string
    {
        return 'tool';
    }

    public function label(): string
    {
        return 'Tools';
    }

    public function introspect(NodeTool $tool): ProbeSnapshot
    {
        $tool->loadMissing('node');
        $node = $tool->node;

        if (! $node instanceof Node || $tool->name === '') {
            return new ProbeSnapshot([]);
        }

        $metadata = $this->probeMetadataForTool($tool);

        if (($metadata['probe'] ?? null) === 'docker_images') {
            return $this->withManagedFileProbes($tool, $this->introspectDockerImages($tool, $metadata));
        }

        if (($metadata['probe'] ?? null) === 'php_cli_runtimes') {
            return $this->withManagedFileProbes($tool, $this->introspectPhpCliRuntimes($tool));
        }

        $script = $this->capabilityProbeContract()->renderOne(
            $tool->name,
            $this->capabilityProbeInput($tool, $metadata),
        );

        $result = $this->scriptDispatcher()->run($node, $tool->name, 'probe', $script);

        return $this->withManagedFileProbes($tool, new ProbeSnapshot([
            $tool->name => $this->capabilityProbeContract()->parseOne($tool->name, $result),
        ]));
    }

    /**
     * @param  list<NodeTool>  $tools
     * @return array<string, ProbeSnapshot>
     */
    public function introspectMany(array $tools): array
    {
        $snapshots = [];
        $batch = [];
        $batchedTools = [];
        $node = null;

        foreach ($tools as $tool) {
            $tool->loadMissing('node');
            $toolNode = $tool->node;

            if (! $toolNode instanceof Node || $tool->name === '') {
                $snapshots[$tool->name] = new ProbeSnapshot([]);

                continue;
            }

            $metadata = $this->probeMetadataForTool($tool);

            if (($metadata['probe'] ?? null) === 'docker_images') {
                $snapshots[$tool->name] = $this->withManagedFileProbes($tool, $this->introspectDockerImages(
                    $tool,
                    $metadata,
                ));

                continue;
            }

            if (($metadata['probe'] ?? null) === 'php_cli_runtimes') {
                $snapshots[$tool->name] = $this->withManagedFileProbes($tool, $this->introspectPhpCliRuntimes($tool));

                continue;
            }

            if ($node !== null && $node->id !== $toolNode->id) {
                $snapshots[$tool->name] = $this->introspect($tool);

                continue;
            }

            $node = $toolNode;
            $batch[$tool->name] = $this->capabilityProbeInput($tool, $metadata);
            $batchedTools[$tool->name] = $tool;
        }

        if ($batch === [] || ! $node instanceof Node) {
            return $snapshots;
        }

        $script = $this->capabilityProbeContract()->renderMany($batch);
        $result = $this->scriptDispatcher()->run($node, 'tool-catalog', 'probe-many', $script);

        foreach ($this->capabilityProbeContract()->parseMany($result, array_keys($batch)) as $name => $observation) {
            $snapshots[$name] = new ProbeSnapshot([$name => $observation]);
        }

        foreach (array_keys($batch) as $toolName) {
            $snapshots[$toolName] ??= new ProbeSnapshot([]);
        }

        foreach ($batchedTools as $toolName => $tool) {
            $snapshots[$toolName] = $this->withManagedFileProbes($tool, $snapshots[$toolName] ?? new ProbeSnapshot([]));
        }

        return $snapshots;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function capabilityProbeInput(NodeTool $tool, array $metadata): ToolCapabilityProbeInput
    {
        return ToolCapabilityProbeInput::fromMetadata(
            metadata: $metadata,
            binaryFallback: $tool->name,
            containerOverride: $this->expectedContainerName($tool),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function probeMetadataForTool(NodeTool $tool): array
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);
        $metadata = $catalog->probeMetadata($tool->name) ?? [];
        $definition = $catalog->definition($tool->name);

        if (! $definition instanceof UserScopedCliTool) {
            return $this->probeMetadataForNode($tool, $metadata);
        }

        return $this->probeMetadataForNode(
            $tool,
            $definition->probeMetadataForUser($this->userScopedCliProbeUser($tool, $definition)),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function probeMetadataForNode(NodeTool $tool, array $metadata): array
    {
        if ($tool->name !== 'docker' || $this->toolNodeOperatingSystem($tool) !== 'macos') {
            return $metadata;
        }

        unset($metadata['service'], $metadata['repair_commands']);

        return $metadata;
    }

    private function toolNodeOperatingSystem(NodeTool $tool): ?string
    {
        $tool->loadMissing('node');

        if (! $tool->node instanceof Node) {
            return null;
        }

        $catalog = $this->catalog ?? app(ToolCatalog::class);

        return $catalog->operatingSystemForPlatform($tool->node->platform);
    }

    private function userScopedCliProbeUser(NodeTool $tool, UserScopedCliTool $definition): string
    {
        $config = is_array($tool->config) ? $tool->config : [];
        $configuredUser = UserScopedCliUsers::normalize($config['default_user'] ?? null);

        if ($configuredUser !== null) {
            return $configuredUser;
        }

        $nodeUser = UserScopedCliUsers::normalize($tool->node instanceof Node ? $tool->node->user : null);

        return $nodeUser ?? 'orbit';
    }

    private function introspectPhpCliRuntimes(NodeTool $tool): ProbeSnapshot
    {
        $node = $tool->node;

        if (! $node instanceof Node) {
            return new ProbeSnapshot([]);
        }

        $desiredVariant = $this->phpCliVariantForTool($tool);
        // During compatibility, nodes intentionally run the retained standard runtime
        // even when role desire is coverage. Classify against that effective runtime
        // so doctor does not permanently emit coverage_missing / reinstall loops.
        $runtimeCatalog = PhpCliArtifactCatalog::load();
        $effectiveVariant = $runtimeCatalog->usesCompatibilityContract()
            ? PhpCliVariant::Standard
            : $desiredVariant;
        $definition = $this->catalog?->definition('php-cli') ?? app(ToolCatalog::class)->definition('php-cli');

        if (! $definition instanceof PhpCliTool) {
            return new ProbeSnapshot([]);
        }

        $script = $definition->runtimeProbeScript($effectiveVariant);
        $result = $this->scriptDispatcher()->run($node, $tool->name, 'probe-php-cli', $script);
        $minors = [];
        $classifier = new PhpCliRuntimeClassifier;
        $allOk = true;
        $anyPresent = false;

        foreach (preg_split('/\R/', trim($result->stdout)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode('|', $line);

            if (count($parts) < 8) {
                continue;
            }

            [
                $minor,
                $expectedPatch,
                $presentRaw,
                $patch,
                $pcovLoadedRaw,
                $pcovStartRaw,
                $pcovEnabledRaw,
                $riOkRaw,
            ] = $parts;

            $observed = [
                'present' => $presentRaw === '1',
                'patch' => $patch !== '' ? $patch : null,
                'expected_patch' => $expectedPatch,
                'extension_loaded_pcov' => $pcovLoadedRaw === '1',
                'function_exists_pcov_start' => $pcovStartRaw === '1',
                'pcov_enabled' => $pcovEnabledRaw === '1',
                'ri_pcov_ok' => $riOkRaw === '1',
            ];
            $classification = $classifier->classify($effectiveVariant, $observed);
            $minors[$minor] = [
                ...$observed,
                'classification' => $classification['kind'],
                'ok' => $classification['ok'],
                'summary' => $classification['summary'],
            ];
            $anyPresent = $anyPresent || $observed['present'];
            $allOk = $allOk && $classification['ok'];
        }

        $default = $minors['8.5'] ?? null;
        $defaultPath = '/opt/orbit/php/8.5/bin/php';
        $defaultPresent = ($default['present'] ?? false) === true;
        $complete = $this->phpCliMinorSnapshotIsComplete($minors);
        $probeOk = $result->successful() && $complete && $allOk;
        $matrixCutoverPending =
            $runtimeCatalog->usesCompatibilityContract() && $desiredVariant === PhpCliVariant::Coverage;

        return new ProbeSnapshot([
            $tool->name => [
                // Presence of the default binary is the capability signal.
                // Per-minor and PCOV classification are reported separately.
                'installed' => $probeOk && $defaultPresent,
                'path' => $defaultPresent ? $defaultPath : null,
                'version' => is_string($default['patch'] ?? null) ? $default['patch'] : null,
                'state' => null,
                'variant' => $desiredVariant->value,
                'desired_variant' => $desiredVariant->value,
                'effective_variant' => $effectiveVariant->value,
                'install_contract' => $runtimeCatalog->installContract(),
                'matrix_cutover_pending' => $matrixCutoverPending,
                'minors' => $minors,
                'php_cli_probe_ok' => $probeOk,
                'php_cli_any_present' => $anyPresent,
                'php_cli_minors_complete' => $complete,
                'config_exists' => null,
                'config_hash' => null,
                'secret_exists' => null,
                'secret_hash' => null,
            ],
        ]);
    }

    private function phpCliVariantForTool(NodeTool $tool): PhpCliVariant
    {
        return app(PhpCliVariantResolver::class)->forTool($tool);
    }

    /**
     * @return list<DriftEntry>
     * @mago-expect lint:halstead
     */
    private function checkPhpCliRuntimeState(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        if ($tool->name !== 'php-cli' || $tool->expected_state === 'absent') {
            return [];
        }

        $observed = $snapshot->get($tool->name) ?? [];
        $minors = is_array($observed['minors'] ?? null) ? $observed['minors'] : null;

        // No php-cli probe shape at all — generic capability presence owns the check.
        if ($minors === null) {
            return [];
        }

        $desiredVariant = is_string($observed['desired_variant'] ?? null)
            ? PhpCliVariant::tryFromMixed($observed['desired_variant'])
            : null;
        $desiredVariant ??= $this->phpCliVariantForTool($tool);
        $effectiveVariant = is_string($observed['effective_variant'] ?? null)
            ? PhpCliVariant::tryFromMixed($observed['effective_variant'])
            : null;
        $effectiveVariant ??= $desiredVariant;
        $installContract = is_string($observed['install_contract'] ?? null)
            ? $observed['install_contract']
            : PhpCliArtifactCatalog::load()->installContract();
        $matrixCutoverPending =
            ($observed['matrix_cutover_pending'] ?? false) === true
            || $installContract === PhpCliArtifactCatalog::INSTALL_CONTRACT_COMPATIBILITY
            && $desiredVariant === PhpCliVariant::Coverage;

        // Failed probe, empty stdout, or malformed lines leave minors empty/incomplete.
        // That must never look like "no drift".
        if (! $this->phpCliMinorSnapshotIsComplete($minors)) {
            $observedMinors = array_values(array_filter(
                array_keys($minors),
                static fn (mixed $key): bool => is_string($key) && $key !== '',
            ));

            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.capability_missing',
                    kind: DriftKind::Missing,
                    summary: 'Tool php-cli probe failed or returned incomplete runtime evidence for supported minors.',
                    detail: [
                        'tool' => $tool->name,
                        'variant' => $desiredVariant->value,
                        'desired_variant' => $desiredVariant->value,
                        'effective_variant' => $effectiveVariant->value,
                        'install_contract' => $installContract,
                        'matrix_cutover_pending' => $matrixCutoverPending,
                        'reason' => 'probe_incomplete',
                        'php_cli_probe_ok' => $observed['php_cli_probe_ok'] ?? false,
                        'observed_minors' => $observedMinors,
                        'expected_minors' => PhpCliArtifactCatalog::SUPPORTED_MINORS,
                    ],
                ),
            ];
        }

        $issues = [];

        foreach ($minors as $minor => $state) {
            if (! is_string($minor) || ! is_array($state)) {
                continue;
            }

            if (($state['present'] ?? false) !== true) {
                $issues[] = new DriftEntry(
                    family: $this->key(),
                    key: 'tool.capability_missing',
                    kind: DriftKind::Missing,
                    summary: "Tool php-cli minor {$minor} is missing on the target node.",
                    detail: [
                        'tool' => $tool->name,
                        'minor' => $minor,
                        'variant' => $desiredVariant->value,
                        'desired_variant' => $desiredVariant->value,
                        'effective_variant' => $effectiveVariant->value,
                        'install_contract' => $installContract,
                        'matrix_cutover_pending' => $matrixCutoverPending,
                    ],
                );

                continue;
            }

            if (($state['ok'] ?? false) === true) {
                continue;
            }

            $classification = is_string($state['classification'] ?? null) ? $state['classification'] : '';

            // Only enforce coverage/PCOV after matrix promotion. In compatibility mode the
            // effective runtime is standard, so coverage_missing would false-positive forever.
            if (
                $effectiveVariant === PhpCliVariant::Coverage
                && $classification === PhpCliRuntimeClassifier::KIND_COVERAGE_BROKEN
            ) {
                $issues[] = new DriftEntry(
                    family: $this->key(),
                    key: 'tool.php_cli_coverage_missing',
                    kind: DriftKind::Divergent,
                    summary: "Tool php-cli coverage capability is missing or broken for PHP {$minor}.",
                    detail: [
                        'tool' => $tool->name,
                        'minor' => $minor,
                        'variant' => $desiredVariant->value,
                        'desired_variant' => $desiredVariant->value,
                        'effective_variant' => $effectiveVariant->value,
                        'install_contract' => $installContract,
                        'matrix_cutover_pending' => $matrixCutoverPending,
                        'observed_patch' => $state['patch'] ?? null,
                        'expected_patch' => $state['expected_patch'] ?? null,
                        'extension_loaded_pcov' => $state['extension_loaded_pcov'] ?? null,
                        'function_exists_pcov_start' => $state['function_exists_pcov_start'] ?? null,
                        'pcov_enabled' => $state['pcov_enabled'] ?? null,
                        'ri_pcov_ok' => $state['ri_pcov_ok'] ?? null,
                    ],
                );

                continue;
            }

            $issues[] = new DriftEntry(
                family: $this->key(),
                key: 'tool.version_mismatch',
                kind: DriftKind::Divergent,
                summary: "Tool php-cli minor {$minor} does not match gateway intent.",
                detail: [
                    'tool' => $tool->name,
                    'minor' => $minor,
                    'variant' => $desiredVariant->value,
                    'desired_variant' => $desiredVariant->value,
                    'effective_variant' => $effectiveVariant->value,
                    'install_contract' => $installContract,
                    'matrix_cutover_pending' => $matrixCutoverPending,
                    'classification' => $classification,
                    'observed_patch' => $state['patch'] ?? null,
                    'expected_patch' => $state['expected_patch'] ?? null,
                ],
            );
        }

        return $issues;
    }

    /**
     * A complete per-minor php-cli snapshot includes every supported minor with a state array.
     *
     * @param  array<array-key, mixed>  $minors
     */
    private function phpCliMinorSnapshotIsComplete(array $minors): bool
    {
        if ($minors === []) {
            return false;
        }

        return array_all(PhpCliArtifactCatalog::SUPPORTED_MINORS, fn ($minor) => is_array($minors[$minor] ?? null));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function introspectDockerImages(NodeTool $tool, array $metadata): ProbeSnapshot
    {
        $node = $tool->node;

        if (! $node instanceof Node) {
            return new ProbeSnapshot([]);
        }

        $images = is_array($metadata['images'] ?? null)
            ? array_values(array_filter($metadata['images'], is_string(...)))
            : [];
        $script = <<<'BASH'
            if ! docker info >/dev/null 2>&1; then
                printf '%s\n' 'Docker-compatible provider is unreachable.' >&2
                exit 2
            fi

            found=0
            while IFS= read -r image; do
                [ -n "$image" ] || continue
                if docker image inspect "$image" >/dev/null 2>&1; then
                    printf '%s\n' "$image"
                    found=1
                fi
            done

            exit 0
            BASH;

        $script = 'printf %s '.escapeshellarg(implode("\n", $images)."\n").' | ('.$script.')';
        $result = $this->scriptDispatcher()->run($node, $tool->name, 'probe-images', $script);
        $catalog = app(PhpRuntimeCatalog::class);
        $observedImages = array_values(array_filter(
            preg_split('/\R/', trim($result->stdout)) ?: [],
            static fn (string $image): bool => in_array($image, $images, true) && $catalog->isApprovedImage($image),
        ));
        $versions = array_values(array_map(
            $catalog->versionForImage(...),
            $observedImages,
        ));

        return new ProbeSnapshot([
            $tool->name => [
                'installed' => $result->successful() && $observedImages !== [],
                'path' => null,
                'version' => $versions[0] ?? null,
                'state' => null,
                'images' => $observedImages,
                'versions' => $versions,
                'image_inventory_available' => $result->successful(),
                'image_inventory_error' => $result->successful() ? null : trim($result->errorOutput()),
                'config_exists' => null,
                'config_hash' => null,
                'secret_exists' => null,
                'secret_hash' => null,
            ],
        ]);
    }

    /**
     * @return list<DriftEntry>
     */
    public function diff(NodeTool $tool, ProbeSnapshot $snapshot, bool $allowProvisioning = false): array
    {
        $intentIssues = [
            ...$this->checkRecordCompleteness($tool),
            ...$this->checkNodeEligibility($tool, $allowProvisioning),
            ...$this->checkDefinition($tool),
        ];
        $capabilityProbeIssues = $this->checkCapabilityProbe($tool, $snapshot);

        if ($capabilityProbeIssues !== []) {
            return [
                ...$intentIssues,
                ...$capabilityProbeIssues,
                ...$this->checkAgentCredentials($tool),
                ...$this->checkAgentUser($tool),
            ];
        }

        return [
            ...$intentIssues,
            ...$this->checkCapabilityPresence($tool, $snapshot),
            ...$this->checkPhpCliRuntimeState($tool, $snapshot),
            ...$this->checkDockerProviderReachability($tool, $snapshot),
            ...$this->checkContainerState($tool, $snapshot),
            ...$this->checkVersionState($tool, $snapshot),
            ...$this->checkConfigState($tool, $snapshot),
            ...$this->checkCredentialState($tool, $snapshot),
            ...$this->checkAgentCredentials($tool),
            ...$this->checkAgentUser($tool),
            ...($this->agentConsumerUrlProbe ?? app(AgentToolConsumerUrlProbe::class))->check($tool, $snapshot),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(NodeTool $tool): array
    {
        $issues = [];

        if (
            ! is_int($tool->node_id)
            || $tool->name === ''
            || ! in_array($tool->expected_state, self::ExpectedStates, true)
        ) {
            $issues[] = new DriftEntry(
                family: $this->key(),
                key: 'tool.record_incomplete',
                kind: DriftKind::Missing,
                summary: "Tool record {$tool->name} is missing required fields.",
            );
        }

        if (($reason = $this->managedConfigIntentError($tool)) !== null) {
            $issues[] = new DriftEntry(
                family: $this->key(),
                key: 'tool.record_incomplete',
                kind: DriftKind::Missing,
                summary: "Tool {$tool->name} managed configuration intent is incomplete.",
                detail: [
                    'tool' => $tool->name,
                    'field' => 'managed_config',
                    'reason' => $reason,
                ],
            );
        }

        if (($reason = $this->managedSecretIntentError($tool)) !== null) {
            $issues[] = new DriftEntry(
                family: $this->key(),
                key: 'tool.record_incomplete',
                kind: DriftKind::Missing,
                summary: "Tool {$tool->name} managed credential intent is incomplete.",
                detail: [
                    'tool' => $tool->name,
                    'field' => 'managed_secret',
                    'reason' => $reason,
                ],
            );
        }

        return $issues;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkNodeEligibility(NodeTool $tool, bool $allowProvisioning): array
    {
        $tool->loadMissing('node');

        if (! $tool->node instanceof Node) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Tool {$tool->name} points at a missing node.",
                ),
            ];
        }

        $node = $tool->node;
        $allowedStatus = $node->isActive() || $allowProvisioning && $node->isProvisioning();

        $allowedRole =
            $this->isToolNode($tool)
            || $allowProvisioning && app(NodeRoleAssignments::class)->nodeHasConvergingToolHostRole($node);

        if (! $allowedStatus || ! $allowedRole) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Tool {$tool->name} targets node {$node->name}, which is not an active managed-tool node.",
                    detail: [
                        'node' => $node->name,
                        'role' => $node->displayRole(),
                        'status' => $node->status->value,
                    ],
                ),
            ];
        }

        return [];
    }

    private function isToolNode(NodeTool $tool): bool
    {
        $assignments = app(NodeRoleAssignments::class);

        if ($tool->name === 'caddy') {
            return $assignments->nodeHostsOrbitCaddy($tool->node);
        }

        if ($tool->name === 'node-exporter') {
            return $assignments->nodeCanHostMetricsExporter($tool->node);
        }

        return $assignments->nodeCanHostManagedTools($tool->node);
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkDefinition(NodeTool $tool): array
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);

        if ($tool->name !== '' && $catalog->supports($tool->name)) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.definition_missing',
                kind: DriftKind::Missing,
                summary: "Tool {$tool->name} is not present in the Orbit tool catalog.",
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkCapabilityProbe(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($tool->name);

        if (($observed['capability_probe_ok'] ?? true) !== false) {
            return [];
        }

        $error = is_string($observed['capability_probe_error'] ?? null)
            ? $observed['capability_probe_error']
            : 'Capability probe returned no reliable observation.';

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.capability_probe_failed',
                kind: DriftKind::Unverifiable,
                summary: "Tool {$tool->name} capability could not be inspected.",
                detail: [
                    'tool' => $tool->name,
                    'node' => $tool->node?->name,
                    'error' => $error,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkCapabilityPresence(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        if ($tool->expected_state === 'absent') {
            return [];
        }

        $observed = $snapshot->get($tool->name);

        if (($observed['capability_probe_ok'] ?? true) === false) {
            return [];
        }

        // Only suppress the generic check when checkPhpCliRuntimeState has a complete
        // per-minor snapshot to classify. Empty/malformed minors must not look healthy.
        if ($tool->name === 'php-cli') {
            $minors = $snapshot->get($tool->name)['minors'] ?? null;

            if (is_array($minors) && $this->phpCliMinorSnapshotIsComplete($minors)) {
                return [];
            }

            // Incomplete or empty minors: checkPhpCliRuntimeState emits probe_incomplete.
            // Skip the generic path to avoid double-reporting the same failure.
            if (is_array($minors)) {
                return [];
            }
        }

        if (($observed['installed'] ?? null) === true) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.capability_missing',
                kind: DriftKind::Missing,
                summary: "Tool {$tool->name} is missing on the target node.",
                detail: [
                    'tool' => $tool->name,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkDockerProviderReachability(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        if ($tool->name !== 'docker' || $tool->expected_state === 'absent') {
            return [];
        }

        $observed = $snapshot->get($tool->name);

        if (($observed['installed'] ?? null) !== true || ($observed['provider_reachable'] ?? null) !== false) {
            return [];
        }

        $operatingSystem = $this->toolNodeOperatingSystem($tool);
        $summary = $operatingSystem === 'macos'
            ? 'No reachable Docker-compatible provider was found on the macOS node.'
            : 'Docker is installed but the Docker provider is not reachable on the target node.';
        $detail = [
            'tool' => $tool->name,
            'provider' => 'docker-compatible',
        ];

        if (is_string($observed['provider_error'] ?? null) && trim($observed['provider_error']) !== '') {
            $detail['provider_error'] = trim($observed['provider_error']);
        }

        if ($operatingSystem === 'macos') {
            $detail = [
                ...$detail,
                'recommended_provider' => 'colima',
                'remediation_commands' => [
                    'brew install docker colima',
                    'colima start --runtime docker',
                ],
                'compatible_existing_providers' => [
                    'colima',
                    'orbstack',
                    'docker-desktop',
                ],
                'provider_note' => 'OrbStack and Docker Desktop are compatible when already installed and licensed or allowed; Colima is the default recommendation when no provider is reachable.',
            ];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.docker_provider_unreachable',
                kind: DriftKind::Missing,
                summary: $summary,
                detail: $detail,
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkVersionState(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        if ($tool->expected_version === null || $tool->expected_version === '') {
            return [];
        }

        $observed = $snapshot->get($tool->name);

        if (($observed['installed'] ?? null) !== true) {
            return [];
        }

        $version = is_string($observed['version'] ?? null) ? $observed['version'] : null;

        $expectedVersion = (string) $tool->expected_version;

        if (
            $version === null
            || $this->usesFloatingVersionTarget($tool, $expectedVersion)
            || str_starts_with($version, $expectedVersion)
        ) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.version_mismatch',
                kind: DriftKind::Divergent,
                summary: "Tool {$tool->name} version differs from gateway intent.",
                detail: [
                    'tool' => $tool->name,
                    'expected_version' => $tool->expected_version,
                    'observed_version' => $version,
                ],
            ),
        ];
    }

    private function usesFloatingVersionTarget(NodeTool $tool, string $expectedVersion): bool
    {
        if ($tool->name !== 'claude-code') {
            return false;
        }

        return in_array(strtolower(trim($expectedVersion)), ['latest', 'stable'], strict: true);
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkContainerState(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        $expectedHash = $this->expectedContainerSpecHash($tool);

        if ($expectedHash === null) {
            return [];
        }

        $observed = $snapshot->get($tool->name);

        if (($observed['installed'] ?? null) !== true) {
            return [];
        }

        $containerName = $this->expectedContainerName($tool) ?? $tool->name;

        if (($observed['container_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.container_missing',
                    kind: DriftKind::Missing,
                    summary: "Tool {$tool->name} container {$containerName} is missing.",
                    detail: [
                        'tool' => $tool->name,
                        'container' => $containerName,
                    ],
                ),
            ];
        }

        if (($observed['container_exists'] ?? null) !== true) {
            return [];
        }

        $observedState = is_string($observed['container_state'] ?? null)
            ? $observed['container_state']
            : null;

        if ($observedState !== 'running') {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.container_not_running',
                    kind: DriftKind::Divergent,
                    summary: "Tool {$tool->name} container {$containerName} is not running.",
                    detail: [
                        'tool' => $tool->name,
                        'container' => $containerName,
                        'observed_state' => $observedState,
                    ],
                ),
            ];
        }

        $observedHash = is_string($observed['container_spec_hash'] ?? null)
            ? $observed['container_spec_hash']
            : null;

        if ($observedHash !== null && hash_equals($expectedHash, $observedHash)) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.container_spec_mismatch',
                kind: DriftKind::Divergent,
                summary: "Tool {$tool->name} container {$containerName} differs from gateway intent.",
                detail: [
                    'tool' => $tool->name,
                    'container' => $containerName,
                    'expected_hash' => $expectedHash,
                    'observed_hash' => $observedHash,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkConfigState(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        $file = $this->managedConfigFile($tool);

        if (! $file instanceof ManagedFile) {
            return [];
        }

        $observed = $snapshot->get($tool->name);

        if (($observed['installed'] ?? null) !== true) {
            return [];
        }

        $probe = $this->managedFileProbeFromSnapshot($observed, 'config');

        if (! $probe instanceof ManagedFileProbe) {
            return [];
        }

        return $this->managedFileDriftFromPlan(
            tool: $tool,
            plan: $file->plan($probe),
            probe: $probe,
            signals: new ManagedFileDriftSignals(
                missingKey: 'tool.config_missing',
                mismatchKey: 'tool.config_mismatch',
                probeFailedKey: 'tool.config_probe_failed',
                label: 'managed configuration',
            ),
        );
    }

    private function managedConfigFile(NodeTool $tool): ?ManagedFile
    {
        $intent = $this->managedConfigIntent($tool);

        if ($intent === null) {
            return null;
        }

        try {
            return ManagedFile::fromIntent($intent, localExecutor: $this->localExecutor());
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function managedConfigIntentError(NodeTool $tool): ?string
    {
        $intent = $this->managedConfigIntent($tool);

        return $intent === null ? null : $this->managedFileIntentError($intent);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function managedConfigIntent(NodeTool $tool): ?array
    {
        $config = is_array($tool->config) ? $tool->config : [];
        $managedConfig = $config['managed_config'] ?? null;

        return is_array($managedConfig) ? $managedConfig : null;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkCredentialState(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        $file = $this->managedSecretFile($tool);

        if (! $file instanceof ManagedFile) {
            return [];
        }

        $observed = $snapshot->get($tool->name);

        if (($observed['installed'] ?? null) !== true) {
            return [];
        }

        $probe = $this->managedFileProbeFromSnapshot($observed, 'secret');

        if (! $probe instanceof ManagedFileProbe) {
            return [];
        }

        return $this->managedFileDriftFromPlan(
            tool: $tool,
            plan: $file->plan($probe),
            probe: $probe,
            signals: new ManagedFileDriftSignals(
                missingKey: 'tool.credentials_missing',
                mismatchKey: 'tool.credentials_mismatch',
                probeFailedKey: 'tool.credentials_probe_failed',
                label: 'managed credential material',
            ),
        );
    }

    private function managedSecretFile(NodeTool $tool): ?ManagedFile
    {
        $intent = $this->managedSecretIntent($tool);

        if ($intent === null) {
            return null;
        }

        try {
            return ManagedFile::fromIntent(
                intent: $intent,
                defaultMode: '0600',
                defaultDirectoryMode: '0700',
                sensitive: true,
                localExecutor: $this->localExecutor(),
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function managedSecretIntentError(NodeTool $tool): ?string
    {
        $intent = $this->managedSecretIntent($tool);

        return $intent === null
            ? null
            : $this->managedFileIntentError(
                $intent,
                defaultMode: '0600',
                defaultDirectoryMode: '0700',
                sensitive: true,
            );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function managedSecretIntent(NodeTool $tool): ?array
    {
        $credentials = is_array($tool->credentials) ? $tool->credentials : [];
        $managedSecret = $credentials['managed_secret'] ?? null;

        return is_array($managedSecret) ? $managedSecret : null;
    }

    private function expectedContainerSpecHash(NodeTool $tool): ?string
    {
        $config = is_array($tool->config) ? $tool->config : [];
        $container = is_array($config['container'] ?? null) ? $config['container'] : null;

        if ($container === null) {
            return null;
        }

        if ($tool->name === 'caddy') {
            return OrbitCaddyContainer::fromConfig($container)->specHash();
        }

        $hash = $container['spec_hash'] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    private function expectedContainerName(NodeTool $tool): ?string
    {
        $config = is_array($tool->config) ? $tool->config : [];
        $container = is_array($config['container'] ?? null) ? $config['container'] : [];
        $name = $container['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function withManagedFileProbes(NodeTool $tool, ProbeSnapshot $snapshot): ProbeSnapshot
    {
        $tool->loadMissing('node');
        $observed = $snapshot->get($tool->name);

        if (! $tool->node instanceof Node || ($observed['installed'] ?? null) !== true) {
            return $snapshot;
        }

        if (($file = $this->managedConfigFile($tool)) instanceof ManagedFile) {
            $observed = [
                ...$observed,
                ...$this->managedFileProbeSnapshot('config', $file->probe($tool->node)),
            ];
        }

        if (($file = $this->managedSecretFile($tool)) instanceof ManagedFile) {
            $observed = [
                ...$observed,
                ...$this->managedFileProbeSnapshot('secret', $file->probe($tool->node)),
            ];
        }

        return new ProbeSnapshot([
            ...$snapshot->items,
            $tool->name => $observed,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function managedFileProbeSnapshot(string $prefix, ManagedFileProbe $probe): array
    {
        return [
            "{$prefix}_probe_reachable" => $probe->reachable,
            "{$prefix}_exists" => $probe->exists,
            "{$prefix}_hash" => $probe->hash,
            "{$prefix}_mode" => $probe->mode,
            "{$prefix}_probe_error" => $probe->error,
        ];
    }

    /**
     * @param  array<string, mixed>  $observed
     */
    private function managedFileProbeFromSnapshot(array $observed, string $prefix): ?ManagedFileProbe
    {
        $reachable = $observed["{$prefix}_probe_reachable"] ?? null;

        if (! is_bool($reachable)) {
            return null;
        }

        return new ManagedFileProbe(
            reachable: $reachable,
            exists: ($observed["{$prefix}_exists"] ?? null) === true,
            hash: is_string($observed["{$prefix}_hash"] ?? null) ? $observed["{$prefix}_hash"] : null,
            mode: is_string($observed["{$prefix}_mode"] ?? null) ? $observed["{$prefix}_mode"] : null,
            error: is_string($observed["{$prefix}_probe_error"] ?? null) ? $observed["{$prefix}_probe_error"] : null,
        );
    }

    /**
     * @return list<DriftEntry>
     */
    private function managedFileDriftFromPlan(
        NodeTool $tool,
        ManagedFilePlan $plan,
        ManagedFileProbe $probe,
        ManagedFileDriftSignals $signals,
    ): array {
        if ($plan->status === ConvergenceStatus::Ok) {
            return [];
        }

        $detail = [
            'tool' => $tool->name,
            ...$plan->details,
        ];

        if ($plan->status === ConvergenceStatus::Unreachable) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: $signals->probeFailedKey,
                    kind: DriftKind::Unverifiable,
                    summary: "Tool {$tool->name} {$signals->label} could not be inspected.",
                    detail: $detail,
                ),
            ];
        }

        if ($plan->status !== ConvergenceStatus::Changed) {
            return [];
        }

        if (! $probe->exists) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: $signals->missingKey,
                    kind: DriftKind::Missing,
                    summary: "Tool {$tool->name} {$signals->label} is missing.",
                    detail: $detail,
                ),
            ];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: $signals->mismatchKey,
                kind: DriftKind::Divergent,
                summary: "Tool {$tool->name} {$signals->label} differs from gateway intent.",
                detail: $detail,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function managedFileIntentError(
        array $intent,
        string $defaultMode = '0644',
        string $defaultDirectoryMode = '0755',
        bool $sensitive = false,
    ): ?string {
        try {
            ManagedFile::fromIntent(
                intent: $intent,
                defaultMode: $defaultMode,
                defaultDirectoryMode: $defaultDirectoryMode,
                sensitive: $sensitive,
            );

            return null;
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkAgentCredentials(NodeTool $tool): array
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);

        if (! $catalog->hasCapability($tool->name, 'credentials')) {
            return [];
        }

        if ($catalog->category($tool->name) !== 'agent') {
            return [];
        }

        $credentials = is_array($tool->credentials) ? $tool->credentials : [];

        if ($credentials === []) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.agent_credentials_missing',
                    kind: DriftKind::Missing,
                    summary: "Tool {$tool->name} declares credentials but no managed credential metadata is present.",
                    detail: [
                        'tool' => $tool->name,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkAgentUser(NodeTool $tool): array
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);

        if ($catalog->category($tool->name) !== 'agent') {
            return [];
        }

        $tool->loadMissing('node');

        if (! $tool->node instanceof Node) {
            return [];
        }

        try {
            $result = $this->localExecutor()->runInternal(
                node: $tool->node,
                commandName: 'internal:agent-runtime:probe',
                transportOptions: [
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => 'tool-agent-runtime.probe',
                    ],
                    'timeout' => 10,
                    'throw' => false,
                ],
            );
        } catch (Throwable $exception) {
            return [
                $this->agentRuntimeProbeFailed(
                    $tool,
                    reason: 'exception',
                    error: $exception->getMessage(),
                ),
            ];
        }

        if (! $result->successful()) {
            return [
                $this->agentRuntimeProbeFailed(
                    $tool,
                    reason: 'non_success',
                    error: $this->summarizeDiagnostic($result->stderr !== '' ? $result->stderr : $result->stdout),
                    exitCode: $result->exitCode,
                ),
            ];
        }

        $data = $this->successData($result->stdout);

        if ($data === []) {
            return [
                $this->agentRuntimeProbeFailed(
                    $tool,
                    reason: 'malformed_payload',
                    error: 'empty or malformed agent runtime probe payload',
                    exitCode: $result->exitCode,
                ),
            ];
        }

        if (($data['runtime_user'] ?? null) !== true) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.agent_user_missing',
                    kind: DriftKind::Missing,
                    summary: "Tool {$tool->name} is installed on a node whose agent runtime user is absent.",
                    detail: [
                        'tool' => $tool->name,
                        'node' => $tool->node->name,
                    ],
                ),
            ];
        }

        if (($data['orbit_cli'] ?? null) !== true) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.agent_orbit_cli_inaccessible',
                    kind: DriftKind::Divergent,
                    summary: "Tool {$tool->name} is installed on a node whose agent runtime user cannot execute the Orbit CLI.",
                    detail: [
                        'tool' => $tool->name,
                        'node' => $tool->node->name,
                    ],
                ),
            ];
        }

        return [];
    }

    private function agentRuntimeProbeFailed(
        NodeTool $tool,
        string $reason,
        string $error,
        ?int $exitCode = null,
    ): DriftEntry {
        $detail = [
            'tool' => $tool->name,
            'node' => $tool->node?->name,
            'reason' => $reason,
            'error' => $this->summarizeDiagnostic($error),
        ];

        if ($exitCode !== null) {
            $detail['exit_code'] = $exitCode;
        }

        return new DriftEntry(
            family: $this->key(),
            key: 'tool.agent_runtime_probe_failed',
            kind: DriftKind::Unverifiable,
            summary: "Tool {$tool->name} agent runtime could not be inspected.",
            detail: $detail,
        );
    }

    private function summarizeDiagnostic(string $value): string
    {
        $normalized = trim(preg_replace(pattern: '/\s+/', replacement: ' ', subject: $value) ?? $value);

        if ($normalized === '') {
            return 'probe failed';
        }

        if (strlen($normalized) > 240) {
            return substr(string: $normalized, offset: 0, length: 237).'...';
        }

        return $normalized;
    }

    private function localExecutor(): RunsInternalCommands
    {
        return $this->localExecutor ?? app(RunsInternalCommands::class);
    }

    private function scriptDispatcher(): ToolScriptDispatcher
    {
        return $this->scripts ?? app(ToolScriptDispatcher::class);
    }

    private function capabilityProbeContract(): ToolCapabilityProbeContract
    {
        return $this->capabilityProbeContract ?? new ToolCapabilityProbeContract;
    }

    /**
     * @return array<string, mixed>
     */
    private function successData(string $output): array
    {
        try {
            /** @var mixed $payload */
            $payload = json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($payload)) {
            return [];
        }

        // Failure envelopes must never be read as empty healthy success data.
        if (array_key_exists('error', $payload)) {
            return [];
        }

        /** @var mixed $success */
        $success = $payload['success'] ?? null;

        if (! is_array($success)) {
            return [];
        }

        /** @var mixed $data */
        $data = $success['data'] ?? null;

        if (! is_array($data) || $data === []) {
            return [];
        }

        foreach (array_keys($data) as $key) {
            if (! is_string($key)) {
                return [];
            }
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
