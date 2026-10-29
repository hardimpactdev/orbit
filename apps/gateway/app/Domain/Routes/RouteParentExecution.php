<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

final readonly class RouteParentExecution extends RoutePhaseExecution
{
    public const array Kinds = ['route.create', 'route.converge', 'route.withdraw', 'route.destroy', 'route.update',
        'route.target_set', 'route.target_unset', 'route.retire_source'];

    protected function command(): string
    {
        return 'route.parent';
    }

    public function sequence(Operation $operation): array
    {
        $plan = ValidatedData::object($operation->state['route_plan'] ?? []);
        $public = ($plan['publication'] ?? null) === 'public' && ($plan['public_edge'] ?? true);
        $production = ($plan['production'] ?? false) === true;
        if (($operation->state['route_branch'] ?? null) === 'rollback_initial') {
            return ['rollback-initial-dns', 'rollback-initial-caddy', 'rollback-initial-php', 'synchronize-web-root-urls.rollback', 'rollback-initial-certificates', 'rollback-initial-firewall'];
        }
        if (($operation->state['route_branch'] ?? null) === 'rollback_targets') {
            return ['rollback-preparations-caddy', 'rollback-preparations-certificates', 'rollback-preparations-record'];
        }
        if (($operation->state['route_branch'] ?? null) === 'rollback_web_root') {
            return $this->webRootSequence($production, true);
        }
        if (($operation->state['route_branch'] ?? null) === 'rollback_publication') {
            return ['rollback-public-edge', 'rollback-public-firewall'];
        }
        if (($operation->state['route_branch'] ?? null) === 'rollback_public_edge') {
            return ['rollback-public-edge'];
        }
        if (($operation->state['route_branch'] ?? null) === 'rollback' || ($plan['mode'] ?? null) === 'withdraw_replacement') {
            return [...($public ? ['rollback-public-edge'] : []), 'rollback-environment', 'rollback-dns', 'rollback-caddy', 'rollback-certificates', 'rollback-record',
                ...(($plan['mode'] ?? null) === 'withdraw_replacement' && ($plan['metrics'] ?? false) ? ['metrics-reconciled'] : [])];
        }

        $sequence = match ($plan['mode'] ?? null) {
            'target_binding' => ['target-source-access', 'state-recorded'],
            'targets_recover' => ['database-committed', 'environment-synchronized', 'target-live-certificates', 'target-live-caddy',
                'router-published', 'vacated-published', 'target-staging-cleanup', 'removal', 'completed'],
            'targets' => ['target-workload-certificates', 'target-runtime-prepared', 'target-workload-caddy', 'target-router-certificates',
                'target-firewall-prepared', 'target-workload-verified', 'database-committed', 'environment-synchronized',
                'target-live-certificates', 'target-live-caddy', 'router-published', 'vacated-published', 'target-staging-cleanup', 'removal', 'completed'],
            'web_root' => ($plan['changed'] ?? false) ? [...$this->webRootSequence($production), 'web-root-certificate-remove'] : ['state-recorded'],
            'publication' => ['publication-stored', ...(($plan['activate_public'] ?? false)
                ? ['public-edge-verified', 'public-activated', 'ingress-firewall'] : (($plan['withdraw_public'] ?? false)
                    ? ['public-withdraw-caddy', 'public-withdraw-firewall', 'public-withdraw-certificates'] : [])), 'publication-completed'],
            'converge' => ['workload-certificate',
                ...($production && ($plan['web_root'] ?? false) ? ['prepare-web-root', 'synchronize-web-root-urls'] : []),
                'publish-sites', ...($production ? ['production-runtime', 'workload-firewall'] : ['workload-prepared']), 'workload-caddy',
                ...(($plan['remote_router'] ?? false) ? ['router-certificate', 'firewall-policy', 'workload-verified', 'router-caddy'] : []),
                'dns-published', ...($public ? ['ingress-certificate', 'public-edge-verified', 'public-activated', 'ingress-firewall'] : []),
                ...(! $production && ($plan['web_root'] ?? false) ? ['synchronize-web-root-urls'] : []), 'activate-route'],
            'withdraw' => ['dns', 'caddy', ...(($plan['targeted'] ?? false) ? ['php'] : []), 'certificates', 'firewall', 'record'],
            'replacement_after_cutover' => [...($public ? ['public-edge-verified', 'public-activated', 'ingress-firewall'] : []),
                'prepare-cleanup', 'cleanup-caddy', 'cleanup-certificates', 'synchronize-current-environment', 'verify-current-workload', 'complete-replacement'],
            'replacement_cleanup' => ['prepare-cleanup', 'cleanup-caddy', 'cleanup-certificates', 'synchronize-current-environment', 'verify-current-workload', 'complete-replacement'],
            'replacement' => ['workload-certificate', 'workload-caddy', 'router-certificate', 'firewall-policy', 'workload-verified', 'router-caddy',
                ...($public ? ['ingress-certificate', 'public-edge-verified'] : []), $production ? 'environment-synchronized' : 'laravel-url',
                'dns-published', 'database-cutover', ...($public ? ['public-activated', 'ingress-firewall'] : []),
                'prepare-cleanup', 'cleanup-caddy', 'cleanup-certificates', 'synchronize-current-environment', 'verify-current-workload', 'complete-replacement'],
            'retire_source' => ['rebuild-transfer-caddy', 'source-php-pools', 'dns-published', 'remove-unused-source-certificate',
                'remove-source-firewall', 'remove-unused-source-router-certificate', 'remove-source-web-root-artifacts', 'complete-source-retirement'],
            'replace_pending' => ['replace-pending'],
            'state_only' => ['state-recorded'],
            default => throw new ResourceOperationException('route.plan_invalid', 'The prepared Route mode is not supported.', 409),
        };

        return [...$sequence, ...(($plan['metrics'] ?? false) ? ['metrics-reconciled'] : [])];
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        parent::assertCompletion($operation, $outcome);
        if ($outcome->status->value !== 'failed') {
            return;
        }
        $plan = ValidatedData::object($operation->state['route_plan'] ?? []);
        $id = $plan['candidate_route_id'] ?? $plan['route_id'] ?? null;
        $route = is_int($id) ? Route::query()->find($id) : null;
        if ($route === null) {
            return;
        }
        if (($operation->state['route_active_stage'] ?? null) === 'metrics-reconciled' || in_array($plan['mode'] ?? null, ['target_binding', 'state_only', 'web_root'], true)) {
            return;
        }
        $stage = ValidatedData::string($operation->state['route_active_stage'] ?? $operation->state['route_failure_stage'] ?? 'preparation');
        $attributes = ['failed_step' => ($plan['mode'] ?? null) === 'withdraw' && ($plan['targeted'] ?? false) ? 'targeted:'.$stage : $stage,
            'error_code' => $outcome->errorCode ?? 'route.child_failed'];
        if (($plan['mode'] ?? null) === 'converge' && in_array($route->status, [RouteStatus::Pending, RouteStatus::Activating], true)) {
            $attributes['status'] = ($route->isApp() && ! $route->hasWebRoot()) || in_array($stage, ['public-edge-verified', 'public-activated', 'ingress-firewall'], true) ? RouteStatus::Activating : RouteStatus::Failed;
        }
        if (($plan['mode'] ?? null) === 'withdraw') {
            $attributes['status'] = RouteStatus::Failed;
        }
        $route->update($attributes);
    }

    /** @return list<string> */
    private function webRootSequence(bool $production, bool $rollback = false): array
    {
        $sequence = ['web-root-stored', 'workload-certificate', ...($production
            ? ['prepare-web-root', 'synchronize-web-root-urls', 'production-runtime'] : ['workload-prepared']),
            'workload-caddy', ...(! $production ? ['synchronize-web-root-urls'] : [])];

        return $rollback ? array_map(static fn (string $stage): string => $stage === 'synchronize-web-root-urls' ? 'synchronize-web-root-urls.rollback' : 'rollback-'.$stage, $sequence) : $sequence;
    }
}
