<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Apps\AppInstanceRuntimeRequirementsData;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppInstanceDriver;
use App\Models\App;
use App\Models\AppAnalyticsBinding;
use App\Models\AppDependencyAuditSummary;
use App\Models\AppInstance;
use App\Models\AppInstanceDatabaseConnectionTarget;
use App\Models\AppInstanceEnvVariable;
use App\Models\AppInstanceRuntimeMount;
use App\Models\AppRuntimeMount;
use App\Models\AppSetupRun;
use App\Models\AppSetupStep;
use App\Models\AppWebSocketBinding;
use App\Models\DatabaseConnectionTarget;
use App\Models\DeploymentRun;
use App\Models\DeployStep;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Schedule;
use App\Models\Workspace;
use App\Models\WorkspaceStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final class RepairHappieNmbpInstance
{
    public const string CANONICAL_APP_NAME = 'happie';

    public const string WORKAROUND_APP_NAME = 'happie-nmbp';

    public const string TARGET_NODE_NAME = 'NMBP';

    public const string TARGET_PATH = '/Users/nckrtl/apps/happie';

    public const string TARGET_DOMAIN = 'happie.nmbp';

    public const string INSTANCE_NAME = 'nmbp';

    /**
     * @return array{
     *     executed: bool,
     *     actions: list<string>,
     * }
     */
    public function preview(): array
    {
        $context = $this->resolveContext();

        return [
            'executed' => false,
            'actions' => $this->plannedActions($context),
        ];
    }

    /**
     * @return array{
     *     executed: bool,
     *     actions: list<string>,
     * }
     */
    public function execute(): array
    {
        $context = $this->resolveContext();
        $actions = $this->plannedActions($context);

        DB::transaction(function () use ($context): void {
            if ($context['workaroundApp'] instanceof App) {
                $canonicalInstance = $this->ensureNmbpInstance($context);
                $this->reassignDependents($context, $canonicalInstance);
                $context['workaroundApp']->delete();

                return;
            }

            if (! $context['canonicalInstance'] instanceof AppInstance) {
                throw new RuntimeException('Canonical app instance "'.self::INSTANCE_NAME.'" is missing.');
            }

            $this->reassignCanonicalLegacyDependents($context['canonicalApp'], $context['canonicalInstance']);
        });

        return [
            'executed' => true,
            'actions' => $actions,
        ];
    }

    /**
     * @return array{
     *     canonicalApp: App,
     *     workaroundApp: App|null,
     *     targetNode: Node|null,
     *     canonicalInstance: AppInstance|null,
     * }
     */
    private function resolveContext(): array
    {
        $canonicalApp = App::query()->where('name', self::CANONICAL_APP_NAME)->first();

        if (! $canonicalApp instanceof App) {
            throw new RuntimeException('Canonical app "'.self::CANONICAL_APP_NAME.'" is missing.');
        }

        $workaroundApp = App::query()->with('node')->where('name', self::WORKAROUND_APP_NAME)->first();

        if (! $workaroundApp instanceof App) {
            $canonicalInstance = AppInstance::query()
                ->where('app_id', $canonicalApp->id)
                ->where('name', self::INSTANCE_NAME)
                ->first();

            if (! $canonicalInstance instanceof AppInstance) {
                throw new RuntimeException(
                    'Workaround app "'
                    .self::WORKAROUND_APP_NAME
                    .'" is missing and canonical app instance "'
                    .self::INSTANCE_NAME
                    .'" is missing.',
                );
            }

            $this->assertNoCanonicalLegacyWorkspaceStepConflict($canonicalApp, $canonicalInstance);
            $this->assertNoCanonicalLegacyRuntimeMountConflict($canonicalApp, $canonicalInstance);

            return [
                'canonicalApp' => $canonicalApp,
                'workaroundApp' => null,
                'targetNode' => null,
                'canonicalInstance' => $canonicalInstance,
            ];
        }

        $targetNode = $workaroundApp->node;

        if (! $targetNode instanceof Node || $targetNode->name !== self::TARGET_NODE_NAME) {
            throw new RuntimeException('Workaround app must be owned by node "'.self::TARGET_NODE_NAME.'".');
        }

        if ($workaroundApp->path !== self::TARGET_PATH) {
            throw new RuntimeException('Workaround app path must be "'.self::TARGET_PATH.'".');
        }

        if ($workaroundApp->domain !== self::TARGET_DOMAIN) {
            throw new RuntimeException('Workaround app domain must be "'.self::TARGET_DOMAIN.'".');
        }

        $this->assertNoConflicts($canonicalApp, $workaroundApp);

        return [
            'canonicalApp' => $canonicalApp,
            'workaroundApp' => $workaroundApp,
            'targetNode' => $targetNode,
            'canonicalInstance' => null,
        ];
    }

    private function assertNoConflicts(App $canonicalApp, App $workaroundApp): void
    {
        $this->assertNoAppScopedValueConflict($canonicalApp, $workaroundApp, Workspace::class, 'name', 'workspace');

        $workaroundProcessNames = Process::query()
            ->where('owner_type', App::class)
            ->where('owner_id', $workaroundApp->id)
            ->pluck('name')
            ->all();

        if ($workaroundProcessNames !== []) {
            $conflictingProcessExists = Process::query()
                ->where('owner_type', App::class)
                ->where('owner_id', $canonicalApp->id)
                ->whereIn('name', $workaroundProcessNames)
                ->exists();

            if ($conflictingProcessExists) {
                throw new RuntimeException('Canonical app already owns a conflicting process.');
            }
        }

        $targetDomains = ProxyRoute::query()
            ->where('app_id', $workaroundApp->id)
            ->pluck('domain')
            ->map($this->repairedProxyDomain(...))
            ->all();

        if ($targetDomains !== []) {
            if (count($targetDomains) !== count(array_unique($targetDomains))) {
                throw new RuntimeException('Workaround app proxy routes would map to duplicate canonical domains.');
            }

            $conflictingDomainExists = ProxyRoute::query()
                ->whereIn('domain', $targetDomains)
                ->where('app_id', '!=', $workaroundApp->id)
                ->exists();

            if ($conflictingDomainExists) {
                throw new RuntimeException('Canonical app already owns a conflicting proxy route.');
            }
        }

        $this->assertNoAppScopedValueConflict(
            $canonicalApp,
            $workaroundApp,
            AppSetupStep::class,
            'sort_order',
            'app setup step',
        );
        $this->assertNoAppScopedValueConflict(
            $canonicalApp,
            $workaroundApp,
            DatabaseConnectionTarget::class,
            'env_prefix',
            'database connection target',
        );
        $this->assertNoAppScopedValueConflict(
            $canonicalApp,
            $workaroundApp,
            AppRuntimeMount::class,
            'target',
            'runtime mount',
        );
        $this->assertNoCanonicalInstanceRuntimeMountConflict($canonicalApp, $workaroundApp);
        $this->assertNoAppScopedValueConflict(
            $canonicalApp,
            $workaroundApp,
            DeployStep::class,
            'sort_order',
            'deploy step',
        );
        $this->assertNoAppScopedValueConflict(
            $canonicalApp,
            $workaroundApp,
            AppDependencyAuditSummary::class,
            'manager',
            'dependency audit summary',
        );
        $this->assertNoSingletonAppConflict(
            $canonicalApp,
            $workaroundApp,
            AppAnalyticsBinding::class,
            'analytics binding',
        );
        $this->assertNoSingletonAppConflict(
            $canonicalApp,
            $workaroundApp,
            AppWebSocketBinding::class,
            'websocket binding',
        );
        $this->assertNoAppInstanceValueConflict(
            $canonicalApp,
            $workaroundApp,
            AppInstanceEnvVariable::class,
            'key',
            'app instance env variable',
        );
        $this->assertNoAppInstanceValueConflict(
            $canonicalApp,
            $workaroundApp,
            AppInstanceDatabaseConnectionTarget::class,
            'env_prefix',
            'app instance database connection target',
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function assertNoAppScopedValueConflict(
        App $canonicalApp,
        App $workaroundApp,
        string $modelClass,
        string $column,
        string $description,
    ): void {
        $workaroundValues = $modelClass::query()->where('app_id', $workaroundApp->id)->pluck($column)->all();

        if ($workaroundValues === []) {
            return;
        }

        $conflictExists = $modelClass::query()
            ->where('app_id', $canonicalApp->id)
            ->whereIn($column, $workaroundValues)
            ->exists();

        if ($conflictExists) {
            throw new RuntimeException('Canonical app already owns a conflicting '.$description.'.');
        }
    }

    private function assertNoCanonicalInstanceRuntimeMountConflict(App $canonicalApp, App $workaroundApp): void
    {
        $workaroundTargets = AppRuntimeMount::query()->where('app_id', $workaroundApp->id)->pluck('target')->all();

        if ($workaroundTargets === []) {
            return;
        }

        $canonicalInstance = AppInstance::query()
            ->where('app_id', $canonicalApp->id)
            ->where('name', self::INSTANCE_NAME)
            ->first();

        if (! $canonicalInstance instanceof AppInstance) {
            return;
        }

        $conflictExists = AppInstanceRuntimeMount::query()
            ->where('app_instance_id', $canonicalInstance->id)
            ->whereIn('target', $workaroundTargets)
            ->exists();

        if ($conflictExists) {
            throw new RuntimeException('Canonical app instance already owns a conflicting runtime mount.');
        }
    }

    private function assertNoCanonicalLegacyWorkspaceStepConflict(
        App $canonicalApp,
        AppInstance $canonicalInstance,
    ): void {
        $conflictExists = WorkspaceStep::query()
            ->where('app_id', $canonicalApp->id)
            ->where('app_instance_id', $canonicalInstance->id)
            ->whereExists(static function (QueryBuilder $query) use ($canonicalApp): void {
                $query
                    ->selectRaw('1')
                    ->from('workspace_steps as legacy_steps')
                    ->where('legacy_steps.app_id', $canonicalApp->id)
                    ->whereNull('legacy_steps.app_instance_id')
                    ->whereColumn('legacy_steps.phase', 'workspace_steps.phase')
                    ->whereColumn('legacy_steps.sort_order', 'workspace_steps.sort_order');
            })
            ->exists();

        if ($conflictExists) {
            throw new RuntimeException('Canonical app instance already owns a conflicting workspace step.');
        }
    }

    private function assertNoCanonicalLegacyRuntimeMountConflict(
        App $canonicalApp,
        AppInstance $canonicalInstance,
    ): void {
        $legacyTargets = AppRuntimeMount::query()->where('app_id', $canonicalApp->id)->pluck('target')->all();

        if ($legacyTargets === []) {
            return;
        }

        $conflictExists = AppInstanceRuntimeMount::query()
            ->where('app_instance_id', $canonicalInstance->id)
            ->whereIn('target', $legacyTargets)
            ->exists();

        if ($conflictExists) {
            throw new RuntimeException('Canonical app instance already owns a conflicting runtime mount.');
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function assertNoSingletonAppConflict(
        App $canonicalApp,
        App $workaroundApp,
        string $modelClass,
        string $description,
    ): void {
        $workaroundExists = $modelClass::query()->where('app_id', $workaroundApp->id)->exists();

        if (! $workaroundExists) {
            return;
        }

        $canonicalExists = $modelClass::query()->where('app_id', $canonicalApp->id)->exists();

        if ($canonicalExists) {
            throw new RuntimeException('Canonical app already owns '.$description.'.');
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function assertNoAppInstanceValueConflict(
        App $canonicalApp,
        App $workaroundApp,
        string $modelClass,
        string $column,
        string $description,
    ): void {
        $workaroundInstanceIds = AppInstance::query()->where('app_id', $workaroundApp->id)->pluck('id')->all();

        if ($workaroundInstanceIds === []) {
            return;
        }

        $workaroundValues = $modelClass::query()
            ->whereIn('app_instance_id', $workaroundInstanceIds)
            ->pluck($column)
            ->all();

        if ($workaroundValues === []) {
            return;
        }

        $canonicalInstance = AppInstance::query()
            ->where('app_id', $canonicalApp->id)
            ->where('name', self::INSTANCE_NAME)
            ->first();

        if (! $canonicalInstance instanceof AppInstance) {
            return;
        }

        $conflictExists = $modelClass::query()
            ->where('app_instance_id', $canonicalInstance->id)
            ->whereIn($column, $workaroundValues)
            ->exists();

        if ($conflictExists) {
            throw new RuntimeException('Canonical app instance already owns a conflicting '.$description.'.');
        }
    }

    /**
     * @param  array{
     *     canonicalApp: App,
     *     workaroundApp: App|null,
     *     targetNode: Node|null,
     *     canonicalInstance: AppInstance|null,
     * }  $context
     * @return list<string>
     */
    private function plannedActions(array $context): array
    {
        $workaroundApp = $context['workaroundApp'];

        if (! $workaroundApp instanceof App) {
            $legacyStepCount = WorkspaceStep::query()
                ->where('app_id', $context['canonicalApp']->id)
                ->whereNull('app_instance_id')
                ->count();
            $legacyRuntimeMountCount = AppRuntimeMount::query()->where('app_id', $context['canonicalApp']->id)->count();

            return [
                'reuse existing app instance "'.self::INSTANCE_NAME.'" on canonical "'.self::CANONICAL_APP_NAME.'"',
                'reassign '
                    .$legacyStepCount
                    .' canonical app-level workspace step record(s) to "'
                    .self::INSTANCE_NAME
                    .'" instance',
                'migrate '
                    .$legacyRuntimeMountCount
                    .' canonical app-level runtime mount record(s) to "'
                    .self::INSTANCE_NAME
                    .'" instance',
            ];
        }

        $workspaceCount = Workspace::query()->where('app_id', $workaroundApp->id)->count();
        $proxyRouteCount = ProxyRoute::query()->where('app_id', $workaroundApp->id)->count();

        return [
            'ensure app instance "'.self::INSTANCE_NAME.'" on canonical "'.self::CANONICAL_APP_NAME.'"',
            'reassign '.$workspaceCount.' workspace record(s) from "'.self::WORKAROUND_APP_NAME.'"',
            'reassign dependent setup/process/proxy records from "'.self::WORKAROUND_APP_NAME.'"',
            'update '.$proxyRouteCount.' proxy route domain(s) to use canonical app slug',
            'delete workaround app "'.self::WORKAROUND_APP_NAME.'"',
        ];
    }

    /**
     * @param  array{
     *     canonicalApp: App,
     *     workaroundApp: App|null,
     *     targetNode: Node|null,
     *     canonicalInstance: AppInstance|null,
     * }  $context
     */
    private function ensureNmbpInstance(array $context): AppInstance
    {
        if (! $context['targetNode'] instanceof Node || ! $context['workaroundApp'] instanceof App) {
            throw new RuntimeException('Workaround app context is required to create the canonical app instance.');
        }

        $driverConfig = new OrbitAppInstanceDriverConfigData(
            node_id: $context['targetNode']->id,
            node: self::TARGET_NODE_NAME,
            path: self::TARGET_PATH,
            document_root: $context['workaroundApp']->document_root,
            domain: self::TARGET_DOMAIN,
        );

        return AppInstance::query()->updateOrCreate([
            'app_id' => $context['canonicalApp']->id,
            'name' => self::INSTANCE_NAME,
        ], [
            'driver' => AppInstanceDriver::Orbit,
            'driver_config' => $driverConfig,
            'runtime_requirements' => new AppInstanceRuntimeRequirementsData,
        ]);
    }

    /**
     * @param  array{
     *     canonicalApp: App,
     *     workaroundApp: App|null,
     *     targetNode: Node|null,
     *     canonicalInstance: AppInstance|null,
     * }  $context
     */
    private function reassignDependents(array $context, AppInstance $canonicalInstance): void
    {
        if (! $context['workaroundApp'] instanceof App) {
            throw new RuntimeException('Workaround app context is required to reassign dependents.');
        }

        $canonicalAppId = $context['canonicalApp']->id;
        $workaroundAppId = $context['workaroundApp']->id;

        Workspace::query()
            ->where('app_id', $workaroundAppId)
            ->update([
                'app_id' => $canonicalAppId,
                'app_instance_id' => $canonicalInstance->id,
            ]);

        WorkspaceStep::query()
            ->where('app_id', $workaroundAppId)
            ->update([
                'app_id' => $canonicalAppId,
                'app_instance_id' => $canonicalInstance->id,
            ]);

        AppSetupStep::query()->where('app_id', $workaroundAppId)->update(['app_id' => $canonicalAppId]);

        AppSetupRun::query()->where('app_id', $workaroundAppId)->update(['app_id' => $canonicalAppId]);

        DatabaseConnectionTarget::query()->where('app_id', $workaroundAppId)->update(['app_id' => $canonicalAppId]);

        AppRuntimeMount::query()
            ->where('app_id', $workaroundAppId)
            ->get()
            ->each(static function (AppRuntimeMount $mount) use ($canonicalInstance): void {
                AppInstanceRuntimeMount::query()->create([
                    'app_instance_id' => $canonicalInstance->id,
                    'source' => $mount->source,
                    'target' => $mount->target,
                    'read_only' => $mount->read_only,
                ]);

                $mount->delete();
            });

        DeployStep::query()->where('app_id', $workaroundAppId)->update(['app_id' => $canonicalAppId]);

        DeploymentRun::query()->where('app_id', $workaroundAppId)->update(['app_id' => $canonicalAppId]);

        Schedule::query()
            ->where('app_id', $workaroundAppId)
            ->update([
                'app_id' => $canonicalAppId,
                'target_name' => self::CANONICAL_APP_NAME,
            ]);

        AppAnalyticsBinding::query()->where('app_id', $workaroundAppId)->update(['app_id' => $canonicalAppId]);

        AppWebSocketBinding::query()->where('app_id', $workaroundAppId)->update(['app_id' => $canonicalAppId]);

        AppDependencyAuditSummary::query()->where('app_id', $workaroundAppId)->update(['app_id' => $canonicalAppId]);

        Process::query()
            ->where('owner_type', App::class)
            ->where('owner_id', $workaroundAppId)
            ->update(['owner_id' => $canonicalAppId]);

        ProxyRoute::query()
            ->where('app_id', $workaroundAppId)
            ->get()
            ->each(function (ProxyRoute $route) use ($canonicalAppId): void {
                $route->forceFill([
                    'app_id' => $canonicalAppId,
                    'domain' => $this->repairedProxyDomain($route->domain),
                ])->save();
            });

        $workaroundInstanceIds = AppInstance::query()->where('app_id', $workaroundAppId)->pluck('id')->all();

        AppInstanceEnvVariable::query()
            ->whereIn('app_instance_id', $workaroundInstanceIds)
            ->update(['app_instance_id' => $canonicalInstance->id]);

        AppInstanceDatabaseConnectionTarget::query()
            ->whereIn('app_instance_id', $workaroundInstanceIds)
            ->update(['app_instance_id' => $canonicalInstance->id]);
    }

    private function reassignCanonicalLegacyDependents(App $canonicalApp, AppInstance $canonicalInstance): void
    {
        WorkspaceStep::query()
            ->where('app_id', $canonicalApp->id)
            ->whereNull('app_instance_id')
            ->update(['app_instance_id' => $canonicalInstance->id]);

        AppRuntimeMount::query()
            ->where('app_id', $canonicalApp->id)
            ->get()
            ->each(static function (AppRuntimeMount $mount) use ($canonicalInstance): void {
                AppInstanceRuntimeMount::query()->create([
                    'app_instance_id' => $canonicalInstance->id,
                    'source' => $mount->source,
                    'target' => $mount->target,
                    'read_only' => $mount->read_only,
                ]);

                $mount->delete();
            });
    }

    private function repairedProxyDomain(string $domain): string
    {
        return str_replace('.'.self::WORKAROUND_APP_NAME.'.', '.'.self::CANONICAL_APP_NAME.'.', $domain);
    }
}
