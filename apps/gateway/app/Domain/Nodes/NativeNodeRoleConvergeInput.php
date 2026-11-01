<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Nodes\Storage\ConfiguredStoragePathValidator;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Routes\NativeCaddyPublicationInput;
use App\Domain\Routes\NativeNodeRolePublicationInput;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\NodeRole;
use App\Support\ValidatedData;

/** Public admission capture for the role sets executed entirely from 209. */
final readonly class NativeNodeRoleConvergeInput
{
    public function __construct(private SwampSshExecutionInput $ssh) {}

    public static function supports(Node $node, RoleName $role): bool
    {
        return self::supportsContribution($node, $role) && $node->roles()->where('role', $role)->exists() && ! $node->roles()->where('role', '!=', $role)->exists();
    }

    /** Implemented forward contributions; owning parents validate role policy and required duties. */
    public static function supportsContribution(Node $node, RoleName $role): bool
    {
        return $node->platform === 'linux' && in_array($role, [RoleName::Database, RoleName::Ingress, RoleName::WebSocket, RoleName::AppProd, RoleName::AppDev], true);
    }

    /** @param array<string,mixed> $snapshot
     * @return array{intent:array<string,mixed>,participant_node_ids:list<int>}
     */
    public function capture(Node $node, NodeRole $assignment, array $snapshot): array
    {
        if (! $node->exists || ! $assignment->exists || $assignment->node_id !== $node->id
            || ! NodeRole::query()->whereKey($assignment->id)->where('node_id', $node->id)->where('role', $assignment->role)->exists()
            || ($snapshot['schema_version'] ?? null) !== 1 || ($snapshot['node_id'] ?? null) !== $node->id || ($snapshot['assignment_id'] ?? null) !== $assignment->id
            || ($snapshot['role'] ?? null) !== $assignment->role->value || ! is_bool($snapshot['created'] ?? null)) {
            throw new ResourceOperationException('node.role_input_invalid', 'Role capture requires the actual recorded assignment and matching snapshot identity.', 422);
        }
        if ($snapshot['metrics_fleet'] !== null || ! self::supportsContribution($node, $assignment->role)) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This role or its Metrics fleet has no supported native contribution.', 422);
        }
        $footprint = ValidatedData::object($snapshot['footprint']);
        $entries = $footprint['artifacts'];
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new ResourceOperationException('operation.kind_unavailable', 'The footprint requires its complete artifact list.', 422);
        }
        foreach ($entries as $artifact) {
            $artifact = ValidatedData::object($artifact);
            $supported = ['agent', 'peer-dns'];
            if (in_array($assignment->role, [RoleName::Ingress, RoleName::WebSocket, RoleName::AppProd, RoleName::AppDev], true)) {
                $supported = [...$supported, 'caddy-package', 'caddy'];
            }
            if (in_array($assignment->role, [RoleName::AppDev, RoleName::AppProd], true)) {
                $supported[] = 'php-runtime';
                if ($assignment->role === RoleName::AppDev) {
                    $supported[] = 'tmpfiles';
                }
            }
            if ($artifact['required'] === true && ! in_array($artifact['name'], $supported, true)) {
                throw new ResourceOperationException('operation.kind_unavailable', 'An applicable role footprint has no native action.', 422);
            }
        }
        $ssh = $this->ssh->forNode($node);
        $intent = ['schema_version' => 1, 'node_id' => $node->id, 'assignment_id' => $assignment->id,
            'created' => $snapshot['created'], 'role' => $assignment->role->value, 'user' => $node->user,
            'agent_sha256' => $snapshot['agent_sha256'], 'prerequisites' => $snapshot['prerequisites'],
            'ssh' => $ssh,
            ...(in_array($assignment->role, [RoleName::Ingress, RoleName::WebSocket, RoleName::AppProd, RoleName::AppDev], true) ? ['caddy' => app(NativeCaddyPublicationInput::class)->forNode($node),
                'caddy_package' => AgentCaddyPackage::input(), 'firewall' => app(NodeRoleFirewallInput::class)->convergeCommands($node, $assignment->role)] : []),
            'footprint' => $footprint, 'cli' => $snapshot['cli'], 'metrics_fleet' => null];

        $participants = [209, $node->id];
        if (in_array($assignment->role, [RoleName::AppDev, RoleName::AppProd], true)) {
            $recipe = new RecipeLoader()->load();
            $versions = NodeRecipeProgram::phpVersions($recipe, app(NodeRecipeProgram::class)->toolsForRoles($node, $recipe, array_values($node->roles->map(static fn (NodeRole $role): RoleName => $role->role)->all())));
            $intent['php'] = app(NodePhpPackagesInput::class)->forNode($node, $versions, $assignment->role);
            $intent['javascript'] = $snapshot['javascript'];
            $prerequisites = ValidatedData::object($intent['prerequisites']);
            $prerequisites['packages'] = array_values(array_filter(
                ValidatedData::stringList($prerequisites['packages']),
                static fn (string $package): bool => ! str_starts_with($package, 'php-'),
            ));
            $intent['prerequisites'] = $prerequisites;
        }
        if ($assignment->role === RoleName::AppDev) {
            $intent['storage'] = app(ConfiguredStoragePathValidator::class)->directIntent(app(NodeSettingsNormalizer::class)->fromStored($node->settings), $node, true);
            $publication = app(NativeNodeRolePublicationInput::class)->capture($node, $assignment->role);
            $intent['publications'] = $publication['publications'];
            $participants = [...$participants, ...$publication['participant_node_ids']];
        }
        if ($assignment->role === RoleName::WebSocket) {
            $runtime = ValidatedData::object($snapshot['runtime']);
            if (($runtime['node_id'] ?? null) !== $node->id || ($runtime['assignment_id'] ?? null) !== $assignment->id
                || ($runtime['user'] ?? null) !== $node->user || ! is_string($runtime['repository'] ?? null)
                || preg_match('~\Ahttps://[^\s@]+\.git\z~D', $runtime['repository']) !== 1
                || ! is_string($runtime['ref'] ?? null) || preg_match('/\A[a-f0-9]{40}\z/D', $runtime['ref']) !== 1
                || ! is_int($runtime['port'] ?? null) || $runtime['port'] < 1 || $runtime['port'] > 65535
                || ! is_string($runtime['install_path'] ?? null) || preg_match('~\A/(?:[A-Za-z0-9_.-]+/){2,}[A-Za-z0-9_.-]+\z~D', $runtime['install_path']) !== 1
                || array_intersect(explode('/', $runtime['install_path']), ['.', '..']) !== []) {
                throw new ResourceOperationException('operation.kind_unavailable', 'WebSocket runtime intent is invalid.', 422);
            }
            $publication = app(NativeNodeRolePublicationInput::class)->capture($node, $assignment->role);
            foreach ($publication['certificates']['leaves'] as $leaf) {
                if ($leaf['node_id'] !== $node->id) {
                    throw new ResourceOperationException('operation.kind_unavailable', 'WebSocket certificate publication requires its own target.', 422);
                }
            }
            $intent['runtime'] = $runtime;
            $intent['certificates'] = $publication['certificates'];
            $intent['publications'] = $publication['publications'];
            $participants = [...$participants, ...$publication['participant_node_ids']];
        }

        return ['intent' => $intent, 'participant_node_ids' => array_values(array_unique($participants))];
    }
}
