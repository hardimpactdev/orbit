<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\ConnServer\ConnServerRollout;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AptRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Tool;
use Illuminate\Database\Eloquent\Builder;

final readonly class ToolConvergeIntent
{
    public static function phaseSeconds(int $count): int
    {
        return min(180000, 500 + 2100 * $count);
    }

    public function __construct(
        private ToolManagerRegistry $managers,
        private ToolPolicy $policy,
        private ToolDefinitionResolver $definitions,
        private ToolPackageProtection $protection,
    ) {}

    /** @param list<int>|null $ids
     * @return list<array<string, mixed>>
     */
    public function capture(Node $node, ?int $owner = null, ?array $ids = null): array
    {
        $active = $this->policy->active();
        $packages = [];
        $protectedPackages = [];
        $tools = Tool::query()->where('node_id', $node->id)->with(['manager', 'aptRepository'])->orderBy('id')->get();
        foreach ($tools as $tool) {
            if (($ids !== null && ! in_array($tool->id, $ids, true)) || $tool->withdrawn()) {
                continue;
            }
            $manager = $this->managers->find($tool->manager->name);
            if ($manager === null || $manager->name() === ToolManagerName::ConnServer) {
                continue;
            }
            $protected = $protectedPackages[$manager->name()->value] ??= $this->protection->forNode($node, $manager->name());
            if (in_array($tool->package, $protected, true) || ($manager instanceof ReservesRolePackages && $manager->roleOwns($tool->package))) {
                continue;
            }
            $definition = $this->definitions->resolve('update', $manager, $tool->package, $tool, null, null, null, $active);
            $package = new AdmittedToolPackage($node, $manager->name(), $tool->package, $definition, $tool, $tool->manager, $protected)->toArray();
            if ($definition->repository !== null) {
                $package['repository_packages'] = $this->definitions->repositoryPackages($active, $definition->repository, $tools, $tool->package, false);
                // Part of the Tools phase digest. An unproven source is bound to this Operation, so every Node converge runs the phase
                // until a write proves the source, however many times its trust was lost since the last recorded digest.
                $package['repository_verified'] = AptSourceTrust::verified(
                    AptRepository::query()->where('node_id', $node->id)->where('name', $definition->repository['name'])->first(), $definition->repository);
                if (! $package['repository_verified'] && $owner !== null) {
                    $package['repository_proof_run'] = $owner;
                }
            }
            if (! $manager->supportsNode($node)) {
                $package['manager_unavailable'] = true;
            }
            $packages[] = $package;
        }

        if ($ids !== null && count($packages) !== count($ids)) {
            throw new ResourceOperationException('tool.scope_invalid', 'Every Tool ID must name a convergable membership on this Node.', 422);
        }
        $this->assertIdle($node->id, self::scopes($packages), $owner);

        return $packages;
    }

    /**
     * @param  array<mixed>  $packages
     * @return list<string>
     */
    public static function scopes(array $packages): array
    {
        $scopes = [];
        foreach ($packages as $package) {
            $manager = is_array($package) && is_string($package['manager'] ?? null) ? ToolManagerName::tryFrom($package['manager']) : null;
            $scopes[] = $manager?->scope()->value ?? '*';
        }
        $scopes = array_values(array_unique($scopes));
        sort($scopes, SORT_STRING);

        return $scopes;
    }

    /** @return Builder<Operation> */
    public static function writers(): Builder
    {
        return Operation::query()->where(static fn ($query) => $query->whereIn('status', OperationStatus::openValues())->orWhereNotNull('lock_key'))
            ->where(static fn ($query) => $query->where('kind', 'like', 'tool.%')->orWhere('kind', ConnServerRollout::Kind)
                ->orWhere(static fn ($node) => $node->where('kind', 'node.converge')
                    ->where(static fn ($intent) => $intent->where('input->_converge->tool_scope', true)
                        ->orWhereJsonLength('input->_converge->intent->tools', '>', 0))));
    }

    /** @param list<string> $scopes */
    public function assertIdle(int $nodeId, array $scopes, ?int $owner = null): void
    {
        if ($scopes === []) {
            return;
        }
        $writers = self::writers()->where(static fn ($query) => $query->where('node_id', $nodeId)->orWhere('kind', ConnServerRollout::Kind))
            ->when($owner !== null, static fn ($query) => $query->whereKeyNot($owner))->get();
        foreach ($writers as $writer) {
            $held = $this->writerScopes($writer, $nodeId);
            if ($held !== [] && (in_array('*', $held, true) || array_intersect($held, $scopes) !== [])) {
                throw new ResourceOperationException('operation.target_busy', 'Another Tool Operation owns a selected manager on this Node.', 409);
            }
        }
    }

    /** @return list<string> */
    private function writerScopes(Operation $writer, int $nodeId): array
    {
        $input = $writer->input;
        if ($writer->kind === ConnServerRollout::Kind) {
            $nodes = data_get($input, 'intent.nodes');

            return ! is_array($nodes) || in_array($nodeId, array_column($nodes, 'node_id'), true) ? [ToolManagerName::ConnServer->value] : [];
        }
        if ($writer->kind === 'node.converge' || $writer->kind === 'tool.converge') {
            $packages = data_get($input, $writer->kind === 'node.converge' ? '_converge.intent.tools' : 'intent.tools');

            return is_array($packages) ? self::scopes($packages) : ['*'];
        }
        $manager = $input['manager'] ?? (is_string($writer->lock_key) ? substr(strrchr($writer->lock_key, ':') ?: ':', 1) : null);

        return [is_string($manager) ? (ToolManagerName::tryFrom($manager)?->scope()->value ?? '*') : '*'];
    }
}
