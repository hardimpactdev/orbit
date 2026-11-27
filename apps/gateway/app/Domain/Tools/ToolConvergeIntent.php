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
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Builder;

final readonly class ToolConvergeIntent
{
    /**
     * A convergence's admission sets each planned Tool's desired state, as a direct request's admission does, so the newest admitted
     * request defines it and no older receipt, read late or not, sets it back.
     *
     * @param  list<array<string, mixed>>  $plans
     */
    public static function admitDesired(array $plans): void
    {
        foreach ($plans as $plan) {
            Tool::query()->whereKey(ValidatedData::integer($plan['id']))->update(['desired_version' => ValidatedData::nullableString($plan['desired_version'] ?? null),
                'version_constraint' => ValidatedData::nullableString($plan['version_constraint'] ?? null),
                'apt_repository_id' => is_int($plan['repository_id'] ?? null) ? $plan['repository_id'] : null]);
        }
    }

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
     * @param  list<int>  $beside  queued Operations the upgrade admits again after this one, which it does not wait for (assertIdle)
     * @param  list<int>  $without  Tools that did not exist yet when the Operation the upgrade admits again was first admitted
     * @return list<array<string, mixed>>
     */
    public function capture(Node $node, ?int $owner = null, ?array $ids = null, array $beside = [], array $without = []): array
    {
        $active = $this->policy->active();
        $packages = [];
        $protectedPackages = [];
        $tools = Tool::query()->where('node_id', $node->id)->whereKeyNot($without)->with(['manager', 'aptRepository'])->orderBy('id')->get();
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
            // The repository the Tool installs from: the convergence publishes it before the packages, the Node's record in database mode
            // and the catalog's definition in file mode (ToolConvergeIntent::repositories).
            $record = $definition->repository === null ? null : $this->repositoryRecord($node, $definition->repository, $active);
            $package = new AdmittedToolPackage($node, $manager->name(), $tool->package, $definition, $tool, $tool->manager, $protected, $record)->toArray();
            if ($record !== null) {
                $package['repository_id'] = $record->id;
                $package['repository_packages'] = $this->definitions->repositoryPackages($record->id, $tools, $tool->package, false);
            }
            if (! $manager->supportsNode($node)) {
                $package['manager_unavailable'] = true;
            }
            $packages[] = $package;
        }

        if ($ids !== null && count($packages) !== count($ids)) {
            throw new ResourceOperationException('tool.scope_invalid', 'Every Tool ID must name a convergable membership on this Node.', 422);
        }
        $this->assertIdle($node->id, self::scopes($packages), $owner, $beside);

        return $packages;
    }

    /**
     * The Node's record of the repository a planned Tool names. In file mode the catalog defines it: a missing record takes the catalog's
     * definition and waits for this convergence to publish it, and a record of that name with another definition refuses the convergence.
     */
    private function repositoryRecord(Node $node, string $name, ActiveToolPolicy $active): AptRepository
    {
        $record = AptRepository::query()->where('node_id', $node->id)->where('name', $name)->first();
        if ($active->mode !== 'file') {
            return $record ?? throw new ResourceOperationException('tool.repository_missing', 'A Tool names an apt repository the Node does not have.', 409);
        }
        $definition = $active->repository($name) ?? throw new ResourceOperationException('tool.not_in_catalog', 'The apt repository is not in the active Tool catalog.', 409);
        if ($record !== null) {
            AptRepositories::assertCatalog($active, $record);

            return $record;
        }
        AptRepositories::assertDistinct($node->id, $definition);

        return AptRepository::query()->create(['node_id' => $node->id, 'name' => $name, 'definition' => $definition, 'status' => AptRepositoryStatus::Failed,
            'error_code' => 'tool.repository_unconfirmed']);
    }

    /**
     * The repositories a convergence publishes before its packages: each one a planned Tool references, held to the owned members that
     * reference it until a member is verified installed. One the Node shares a URI and suite with under another name stops the convergence, as
     * it stops that repository's own add.
     *
     * @param  list<array<string, mixed>>  $plans
     * @return list<array<string, mixed>>
     */
    public function repositories(array $plans): array
    {
        $repositories = [];
        foreach ($plans as $plan) {
            if (! is_int($plan['repository_id'] ?? null) || isset($repositories[$plan['repository_id']])) {
                continue;
            }
            $record = AptRepository::query()->findOrFail($plan['repository_id']);
            AptRepositories::assertDistinct($record->node_id, $record->definition);
            $owned = Tool::query()->where('apt_repository_id', $record->id)->where('owned', true)->with('manager')->get()
                ->reject->withdrawn()->pluck('package')->sort()->values()->all();
            // Its readiness is part of the Tools phase digest, so a Node converge publishes a repository that is not ready whatever the
            // phase recorded before.
            $repositories[$record->id] = ['id' => $record->id, 'repository' => $record->definition, 'repository_packages' => $owned, 'ready' => $record->ready()];
        }

        return array_values($repositories);
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

    /**
     * @param  list<string>  $scopes
     * @param  list<int>  $beside  queued Operations admitted again with `$owner` at the upgrade, which no first dispatch has ordered yet
     */
    public function assertIdle(int $nodeId, array $scopes, ?int $owner = null, array $beside = []): void
    {
        if ($scopes === []) {
            return;
        }
        $writers = self::writers()->where(static fn ($query) => $query->where('node_id', $nodeId)->orWhere('kind', ConnServerRollout::Kind))
            ->whereKeyNot(array_values(array_filter([$owner, ...$beside], 'is_int')))->get();
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
            $intent = $writer->kind === 'node.converge' ? '_converge.intent' : 'intent';
            $packages = data_get($input, $intent.'.tools');
            // Publishing or removing an apt repository writes apt's files like an apt Tool.
            $repositories = data_get($input, $intent.'.repositories');

            return is_array($packages) ? self::scopes([...$packages, ...(is_array($repositories) && $repositories !== [] ? [['manager' => 'apt']] : [])]) : ['*'];
        }
        $manager = $input['manager'] ?? (is_string($writer->lock_key) ? substr(strrchr($writer->lock_key, ':') ?: ':', 1) : null);

        return [is_string($manager) ? (ToolManagerName::tryFrom($manager)?->scope()->value ?? '*') : '*'];
    }
}
