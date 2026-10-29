<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Instances\VpEnvironment\InstanceVpEnvironmentMaterial;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitCommitSha;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * Gateway intent bindings, fresh guards and proven state projection. No deploy loop or Node writes.
 *
 * @phpstan-import-type Descriptor from OperationFamilyExecution
 * @phpstan-import-type RunInput from \App\Domain\Operations\SwampOperationExecutor
 */
final readonly class InstanceDeploymentExecution implements OperationCompletion, OperationFamilyChildren, OperationFamilyExecution
{
    public function __construct(
        private InstanceDeploymentIntent $intents,
        private InstanceDeploymentGuards $guards,
        private InstanceDeploymentRecorder $recorder,
        private OperationStore $operations,
        private InstanceVpEnvironmentMaterial $vp,
        private InstanceDeploymentBudget $budget,
        private OperationChildren $children,
    ) {}

    public function context(Operation $operation): array
    {
        if (InstanceDeploymentIntent::legacy($operation)) {
            return ['descriptors' => [], 'result' => $this->result($operation)];
        }

        return ['descriptors' => $this->descriptors($operation), 'result' => $this->result($operation)];
    }

    /** @return list<Descriptor> */
    private function descriptors(Operation $operation): array
    {
        $instance = Instance::query()->with(['node', 'project'])->findOrFail($operation->target_id);
        $intent = InstanceDeploymentIntent::admitted($operation);
        if ($operation->target_type !== 'instance' || $operation->node_id !== $instance->node_id
            || ($operation->input['instance_id'] ?? null) !== $instance->id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The deployment target does not match admitted intent.', 422);
        }
        $secret = ['$secret' => 'instance.deployment', 'id' => $instance->id];
        $variant = $intent['variant'];
        $candidate = $this->candidate($operation);
        $descriptors = [];
        if ($variant === 'development') {
            $checkout = ['home' => $intent['home'], 'repository' => $intent['repository'], 'instance' => $instance->id];
            $descriptors[] = $this->descriptor('source.resolve', 'source_preparation.resolve', 'instance.resolve-development-target', [
                ...$checkout, 'branch' => $intent['branch'], 'commit' => InstanceDeploymentIntent::commit($operation),
                'protocols' => ValidatedData::stringList(config('orbit.git.checkout_protocols', ['https'])), 'git_read' => $secret,
            ]);
            $commit = InstanceExecutionEvidence::result($operation, 'source.resolve')['commit'] ?? null;
            if (is_string($commit)) {
                $descriptors[] = $this->descriptor('source.checkout', 'source_preparation', 'instance.checkout-development-default', [
                    ...$checkout, 'branch' => $intent['branch'], 'commit' => $commit,
                ]);
            }
        } else {
            $home = $this->home($operation);
            $descriptors[] = $this->descriptor('source.previous', ($variant === 'rollback' ? 'rollback' : 'source_preparation').'.previous', 'instance.inspect-release', $home);
            if ($variant === 'rollback') {
                $release = ValidatedData::object($operation->input['target'] ?? [])['release'] ?? $operation->input['release'];
                $descriptors[] = $this->descriptor('source.retained', 'rollback', 'instance.inspect-release', [...$home, 'name' => $release]);
            } else {
                $descriptors[] = $this->descriptor('source.prepare', 'source_preparation', 'instance.prepare-release', [
                    ...$home, 'name' => InstanceDeploymentIntent::releaseName($operation), 'branch' => $intent['branch'],
                    'commit' => InstanceDeploymentIntent::commit($operation), 'shared_paths' => $intent['shared_paths'], 'git_read' => $secret,
                ], false);
            }
        }
        if ($candidate !== null || $variant === 'development') {
            foreach ($intent['steps'] as $step) {
                $key = $step['phase'].'.'.$step['name'];
                $descriptors[] = ['key' => 'budget.'.$key, 'step' => $key.'.budget', 'transport' => 'gateway',
                    'name' => 'instance.deployment-budget', 'input' => ['instance_id' => $instance->id, 'step' => $key],
                    'resumable' => true, 'required' => true];
                $timeout = InstanceExecutionEvidence::result($operation, 'budget.'.$key)['command_timeout_seconds'] ?? null;
                if (! is_int($timeout)) {
                    continue;
                }
                $input = $variant === 'development'
                    ? ['home' => $intent['home'], 'repository' => $intent['repository'], 'instance' => $instance->id]
                    : ['user' => $intent['user'], 'home' => $intent['home'], 'release' => $candidate['name'] ?? throw $this->unproven()];
                $descriptors[] = $this->descriptor($key, $key, $variant === 'development' ? 'instance.run-development-step' : 'instance.run-deploy-step', [
                    ...$input, 'script' => $secret, 'vp_environment' => $this->vpEnvironment($operation), 'operation' => $operation->id,
                    'step' => $step['name'], 'timeout_seconds' => $timeout, 'secrets' => $secret,
                ], false, $step['required']);
            }
        }
        if ($variant !== 'development' && $candidate !== null) {
            $descriptors[] = $this->descriptor('activation', 'activation', 'instance.activate-release', [
                ...$this->home($operation), 'name' => $candidate['name'], 'commit' => $candidate['commit'],
            ]);
            if ($intent['php']) {
                $descriptors[] = $this->descriptor('php.converge', 'php_refresh.converge', 'php.converge-production-runtime', ValidatedData::object($intent['php_runtime']));
                $descriptors[] = $this->descriptor('php.refresh', 'php_refresh', 'php.refresh-production-cache', ValidatedData::object($intent['php_refresh']));
            }
            if ($variant === 'production') {
                $previous = $this->previous($operation);
                $descriptors[] = $this->descriptor('cleanup', 'cleanup', 'instance.prune-releases', [
                    ...$this->home($operation), 'limit' => DeploymentRelease::RETAINED_PER_HOME,
                    'selected' => $candidate['name'], 'previous' => $previous['name'] ?? null,
                ], true, false);
            }
        }
        foreach (ValidatedData::stringList($operation->state['vp_prune_stores'] ?? []) as $store) {
            $key = 'cleanup.vp.'.substr(hash('sha256', $store), 0, 16);
            $descriptors[] = $this->descriptor($key, $key, 'tool.prune-vp-store', [
                'store' => $store, 'references' => $this->vp->references($operation->node_id ?? throw $this->unproven(), $store),
            ], true, false);
        }
        foreach ($this->childPlan($operation) as $child) {
            $descriptors[] = ['key' => 'child.'.$child['key'], 'step' => $child['key'], 'transport' => 'gateway',
                'name' => 'instance.child-receipt', 'input' => ['child_key' => $child['key']], 'resumable' => true, 'required' => true];
        }
        $descriptors[] = ['key' => 'verification', 'step' => 'verification', 'transport' => 'gateway',
            'name' => 'instance.deployment-receipt', 'input' => ['instance_id' => $instance->id], 'resumable' => true, 'required' => true];

        $order = ['source_preparation' => 0, 'rollback' => 0, 'environment_sync' => 1, 'vp_environment' => 2, 'before_activation' => 3,
            'activation' => 4, 'php_refresh' => 5, 'process_restart' => 6, 'after_activation' => 7, 'cleanup' => 8, 'verification' => 9];
        usort($descriptors, static fn (array $left, array $right): int => [$order[explode('.', $left['step'])[0]], in_array($left['key'], ['child.php_refresh.packages', 'child.source_preparation.projection'], true) ? -1 : 0]
            <=> [$order[explode('.', $right['step'])[0]], in_array($right['key'], ['child.php_refresh.packages', 'child.source_preparation.projection'], true) ? -1 : 0]);

        return $descriptors;
    }

    /** @return list<array{key:string,kind:string,node_id:int|null,input:array<string,mixed>}> */
    private function childPlan(Operation $operation): array
    {
        $intent = InstanceDeploymentIntent::admitted($operation);
        $children = [];
        $child = static fn (string $key, string $kind, array $input): array => [
            'key' => $key, 'kind' => $kind, 'node_id' => $operation->node_id, 'input' => $input,
        ];
        $input = ['instance_id' => $operation->target_id];
        if ($intent['projection_route_id'] !== null) {
            $children[] = $child('source_preparation.projection', 'route.converge', ['route_id' => $intent['projection_route_id'], ...$input]);
        }
        if ($intent['variant'] === 'production') {
            $children[] = $child('environment_sync', 'instance.sync_environment', $input);
        }
        if ($intent['needs_vp']) {
            $children[] = $child('vp_environment', 'instance.prepare_vp_environment', $input);
        }
        if ($intent['variant'] === 'production') {
            $children[] = $child('activation.process_definitions', 'process.install_instance_definitions', $input);
            $children[] = $child('activation.schedule_definitions', 'schedule.install_instance_definitions', $input);
        }
        if ($intent['variant'] !== 'development') {
            if ($intent['php']) {
                $children[] = $child('php_refresh.packages', 'node.prepare_php_packages', ['versions' => [$intent['php_version']], 'profile' => 'app-prod']);
            }
            $children[] = $child('process_restart', 'process.restart_instance', $input);
        }

        return $children;
    }

    public function childContext(Operation $parent): array
    {
        if (InstanceDeploymentIntent::legacy($parent)) {
            return ['intent' => [], 'children' => []];
        }
        $eligible = [];
        foreach ($this->childPlan($parent) as $child) {
            try {
                $this->budget->requireRemaining($parent);
                $this->assertPrerequisites($parent, 'child.'.$child['key']);
                $eligible[] = $child;
            } catch (ResourceOperationException) {
                continue;
            }
        }

        return ['intent' => [], 'children' => $eligible];
    }

    /** @return array<string,mixed>|null */
    private function vpEnvironment(Operation $operation): ?array
    {
        if (! InstanceDeploymentIntent::admitted($operation)['needs_vp']) {
            return null;
        }
        try {
            $result = $this->children->requireSucceeded($operation, 'vp_environment')->result;
        } catch (ResourceOperationException) {
            return null;
        }
        $environment = $result['vp_environment'] ?? null;

        return is_array($environment) ? ValidatedData::object($environment) : null;
    }

    public function input(Operation $operation, array $run): array
    {
        foreach ($this->descriptors($operation) as $descriptor) {
            if ($descriptor['key'] === $run['key'] && $descriptor['name'] === $run['name'] && $descriptor['transport'] === $run['transport']) {
                return $descriptor['input'];
            }
        }
        throw new ResourceOperationException('operation.execution_forbidden', 'The invocation is not part of this deployment.', 422);
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        $this->assertPrerequisites($operation, $run['key']);
        if ($run['key'] === 'verification') {
            return;
        }
        $this->budget->requireRemaining($operation);
        $this->guards->around($operation, function (Instance $instance) use ($operation, $run): void {
            $current = $this->intents->snapshot($instance, rollback: $operation->kind === 'instance.rollback');
            $projection = InstanceDeploymentIntent::admitted($operation)['projection_route_id'];
            if ($projection !== null && $instance->authoritativeRoute()?->id !== $projection) {
                throw new ResourceOperationException('deployment.identity_changed', 'The admitted development Route changed before projection.', 409);
            }
            foreach (['variant', 'project_id', 'repository', 'home', 'user', 'relative_root', 'application_suffix', 'served', 'shared_paths', 'php', 'php_version', 'php_runtime', 'php_refresh'] as $field) {
                if (($current[$field] ?? null) !== (InstanceDeploymentIntent::admitted($operation)[$field] ?? null)) {
                    throw new ResourceOperationException('deployment.identity_changed', 'The Instance ownership or runtime configuration changed after admission.', 409);
                }
            }
            if (! isset($operation->state['deployment_id'])) {
                $row = $this->recorder->start($instance, $operation->triggered_by ?? 'operation', $operation->kind === 'instance.rollback' ? 'rollback' : 'deploy', $operation->callerNodeId());
                $this->operations->change($operation, ['state' => [...($operation->state ?? []), 'deployment_id' => $row->id]]);
            }
            $this->recordPhase($operation, $run['key']);
        });
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if (isset($receipt['error_code'])) {
            return ['error_code' => match ($receipt['error_code']) {
                'instance.commit_not_on_branch' => 'deployment.commit_not_on_branch',
                'instance.checkout_dirty' => 'deployment.checkout_dirty',
                'instance.branch_diverged' => 'deployment.branch_diverged',
                'php.cache_socket_unavailable' => 'app-prod.php_cache_socket_unavailable',
                'php.cache_association_invalid' => 'app-prod.php_cache_association_invalid',
                'php.cache_reset_rejected' => 'app-prod.php_cache_reset_rejected',
                'php.cache_reset_pending' => 'app-prod.php_cache_reset_pending',
                default => $receipt['error_code'],
            }];
        }
        $key = $run['key'];
        if ($key === 'php.refresh' && ($receipt['complete'] ?? false) !== true) {
            return ['error_code' => 'app-prod.php_cache_refresh_failed'];
        }
        if ($key === 'child.source_preparation.projection' && ($receipt['child_status'] ?? null) === 'succeeded') {
            $this->children->requireSucceeded($operation, 'source_preparation.projection');
            Instance::query()->findOrFail($operation->target_id)->update(['development_projection_pending' => false]);
        }
        if ($key === 'cleanup') {
            return $this->cleanup($operation, $receipt);
        }
        $this->recordOutput($operation, $run, $receipt);
        if (in_array($run['name'], ['instance.run-deploy-step', 'instance.run-development-step'], true)) {
            $budget = InstanceExecutionEvidence::result($operation, 'budget.'.$key) ?? throw $this->unproven();
            $receipt = [...$receipt, 'budget_seconds' => $budget['budget_seconds'], 'timeout_seconds' => $budget['timeout_seconds']];
            $step = array_find(InstanceDeploymentIntent::admitted($operation)['steps'], static fn (array $step): bool => $key === $step['phase'].'.'.$step['name']);
            if (($step['required'] ?? true) === false && is_int($receipt['exit_code'] ?? null) && $receipt['exit_code'] !== 0 && ($receipt['timed_out'] ?? null) === false) {
                $row = InstanceDeployment::query()->findOrFail(ValidatedData::integer($operation->state['deployment_id'] ?? null));
                $collector = DeploymentEventCollector::resume($row->events ?? []);
                $collector->output(new DeploymentEvent($key, DeploymentOutputStream::Stderr,
                    'Best-effort step failed (exit '.$receipt['exit_code'].'): '.$step['name']."\n", important: true));
                $row->update(['events' => $collector->events()]);
            }

            if (($receipt['timed_out'] ?? false) === true && $budget['command_timeout_seconds'] < $budget['timeout_seconds']) {
                $receipt['error_code'] = 'deployment.deadline_exceeded';
            }
        }
        if ($key === 'source.previous') {
            return ['previous' => $this->release($operation, $receipt['release'] ?? null)];
        }
        if (in_array($key, ['source.prepare', 'source.retained'], true)) {
            $release = $this->release($operation, $key === 'source.prepare' ? $receipt : ($receipt['release'] ?? null));
            $expected = ValidatedData::object($operation->input['target'] ?? [])['release'] ?? $operation->input['release'] ?? InstanceDeploymentIntent::releaseName($operation);
            if ($release === null || $release['name'] !== $expected || (InstanceDeploymentIntent::commit($operation) !== null && $release['commit'] !== InstanceDeploymentIntent::commit($operation))) {
                throw $this->invalidReceipt();
            }

            $projection = ['release' => $release, 'previous' => $this->previous($operation)];
            $this->projectStep($operation, $operation->kind === 'instance.rollback' ? 'rollback' : 'source_preparation', $projection);

            return $projection;
        }
        if ($key === 'activation') {
            $release = $this->release($operation, $receipt);
            if ($release === null || $release !== $this->candidate($operation)) {
                throw $this->invalidReceipt();
            }
            Instance::query()->findOrFail($operation->target_id)->update(['checkout_path' => $release['path']]);
            $this->projectStep($operation, 'activation', $release);

            return $release;
        }
        if ($key === 'source.resolve' || $key === 'source.checkout') {
            $commit = $receipt['commit'] ?? null;
            if (! is_string($commit) || ! GitCommitSha::isValid($commit)
                || (InstanceDeploymentIntent::commit($operation) !== null && $commit !== InstanceDeploymentIntent::commit($operation))) {
                throw $this->invalidReceipt();
            }
        }

        if (is_array($receipt['output'] ?? null)) {
            $receipt['output_tail'] = mb_scrub(substr(implode('', array_map(static fn (mixed $chunk): string => is_array($chunk) && is_string($chunk['value'] ?? null) ? $chunk['value'] : '', $receipt['output'])), -8000), 'UTF-8');
        }

        return $receipt;
    }

    /** Proven receipts determine the public identity without requiring a reachable Node.
     * @return array{deployment_id:int,release:?string,selected_release:?string,commit:?string}|array{}
     */
    public function result(Operation $operation): array
    {
        if (! isset($operation->state['deployment_id'])) {
            return [];
        }
        if (InstanceDeploymentIntent::legacy($operation)) {
            $row = InstanceDeployment::query()->where('instance_id', $operation->target_id)->find(ValidatedData::integer($operation->state['deployment_id']));

            return $row === null ? [] : ['deployment_id' => $row->id, 'release' => null,
                'selected_release' => null, 'commit' => $row->commit];
        }
        $candidate = $this->candidate($operation);
        $selected = InstanceDeploymentIntent::admitted($operation)['variant'] === 'development' ? null : $this->selected($operation);

        return ['deployment_id' => ValidatedData::integer($operation->state['deployment_id'] ?? null), 'release' => $candidate['name'] ?? null,
            'selected_release' => $selected?->name, 'commit' => $candidate['commit']
                ?? ValidatedData::nullableString(InstanceExecutionEvidence::result($operation, 'source.checkout')['commit'] ?? InstanceDeploymentIntent::commit($operation))];
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        $result = $this->result($operation);
        if ($outcome->result !== $result) {
            throw $this->unproven();
        }
        if (InstanceDeploymentIntent::legacy($operation)) {
            if ($outcome->status !== OperationStatus::Failed || $outcome->errorCode !== 'deployment.interrupted') {
                throw $this->unproven();
            }

            return;
        }
        if ($outcome->status !== OperationStatus::Succeeded) {
            return;
        }
        foreach ($this->descriptors($operation) as $descriptor) {
            $this->verifyDescriptor($operation, $descriptor);
        }
        $result = $this->result($operation);
        if ($result === [] || ! is_string($result['commit']) || ! GitCommitSha::isValid($result['commit'])
            || $outcome->result !== $result
            || (InstanceDeploymentIntent::admitted($operation)['variant'] !== 'development' && $result['selected_release'] !== $result['release'])) {
            throw $this->unproven();
        }
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $this->assertCompletion($operation, $outcome);
        $row = isset($operation->state['deployment_id']) ? InstanceDeployment::query()->find(ValidatedData::integer($operation->state['deployment_id'] ?? null)) : null;
        if ($row === null || $row->status !== 'running') {
            return;
        }
        if (InstanceDeploymentIntent::legacy($operation)) {
            if ($row->instance_id !== $operation->target_id) {
                throw $this->unproven();
            }
            $this->recorder->finish($row, DeploymentResult::failed(null, null, DeploymentFailureBoundary::Operation,
                'deployment.interrupted', commit: $row->commit), $row->events ?? []);

            return;
        }
        $candidate = InstanceDeploymentIntent::storedRelease($this->candidate($operation));
        $instance = Instance::query()->findOrFail($operation->target_id);
        $development = InstanceDeploymentIntent::admitted($operation)['variant'] === 'development';
        $selected = $development ? null : $this->selected($operation);
        $commit = ValidatedData::nullableString(InstanceExecutionEvidence::result($operation, 'source.checkout')['commit'] ?? InstanceDeploymentIntent::commit($operation));
        $failed = $operation->steps()->where('status', 'failed')->orderByDesc('position')->value('name');
        $boundary = is_string($failed) ? $this->boundary($failed) : DeploymentFailureBoundary::Operation;
        $result = $outcome->status === OperationStatus::Succeeded
            ? ($development ? DeploymentResult::checkedOut($commit ?? throw $this->unproven()) : DeploymentResult::succeeded($candidate ?? throw $this->unproven()))
            : DeploymentResult::failed($candidate, $selected, $boundary,
                $outcome->status === OperationStatus::Cancelled ? 'deployment.cancelled' : ($outcome->errorCode ?? 'deployment.interrupted'), commit: $commit);
        if ($development && $result->succeeded) {
            $instance->update(['seed_path' => $instance->checkout_path, 'seed_repository' => $instance->checkout_path, 'seed_commit' => $result->commit]);
        }
        $this->recorder->finish($row, $result, $row->events ?? []);
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        if (InstanceDeploymentIntent::legacy($operation)) {
            return null;
        }
        $candidate = $this->candidate($operation);

        return $candidate === null || InstanceDeploymentIntent::admitted($operation)['variant'] === 'development' ? null : [
            'key' => 'activation', 'transport' => 'instance-deploy-agent', 'name' => 'instance.activate-release', 'result' => $candidate,
        ];
    }

    private function assertPrerequisites(Operation $operation, string $key): void
    {
        foreach ($this->descriptors($operation) as $descriptor) {
            if ($descriptor['key'] === $key) {
                return;
            }
            $this->verifyDescriptor($operation, $descriptor);
        }
        throw $this->unproven();
    }

    /** @param Descriptor $descriptor */
    private function verifyDescriptor(Operation $operation, array $descriptor): void
    {
        if (! $descriptor['required'] && ($descriptor['key'] === 'cleanup' || str_starts_with($descriptor['key'], 'cleanup.'))) {
            return;
        }
        if ($descriptor['name'] === 'instance.child-receipt') {
            $this->children->requireSucceeded($operation, ValidatedData::string($descriptor['input']['child_key']));
        }
        $run = InstanceExecutionEvidence::run($operation, $descriptor['key']);
        if (! $descriptor['required'] && is_array($run) && is_array($run['result'] ?? null)
            && ($run['transport'] ?? null) === $descriptor['transport'] && ($run['name'] ?? null) === $descriptor['name']
            && ($run['step'] ?? null) === $descriptor['step']
            && ($descriptor['key'] === 'cleanup' || (! isset($run['result']['error_code'])
                && is_int($run['result']['exit_code'] ?? null) && $run['result']['exit_code'] !== 0
                && ($run['result']['timed_out'] ?? null) === false))
            && $operation->steps()->where('name', $descriptor['step'])->where('status', 'failed')->exists()) {
            return;
        }
        $this->proof($operation, $descriptor['key'], $descriptor['step'], $descriptor['name'], $descriptor['transport']);
    }

    private function proof(Operation $operation, string $key, string $step, string $name, string $transport = 'instance-deploy-agent'): void
    {
        $run = InstanceExecutionEvidence::run($operation, $key);
        if (! is_array($run) || ($run['step'] ?? null) !== $step || ($run['name'] ?? null) !== $name || ($run['transport'] ?? null) !== $transport
            || ! is_array($run['result'] ?? null) || isset($run['result']['error_code'])
            || (isset($run['result']['exit_code']) && $run['result']['exit_code'] !== 0)
            || ! $operation->steps()->where('name', $step)->where('status', 'succeeded')->exists()
            || InstanceExecutionEvidence::step($operation, $step) !== $run['result']) {
            throw $this->unproven();
        }
    }

    /** @return array<string,mixed> */
    private function home(Operation $operation): array
    {
        $intent = InstanceDeploymentIntent::admitted($operation);

        return ['repository' => $intent['repository'], 'user' => $intent['user'], 'home' => $intent['home'],
            'instance' => $operation->target_id, 'relative_root' => $intent['relative_root'],
            'application_suffix' => $intent['application_suffix'], 'served' => $intent['served']];
    }

    /** @return array{name:string,path:string,commit:string}|null */
    private function candidate(Operation $operation): ?array
    {
        foreach (['source.prepare', 'source.retained'] as $key) {
            $release = InstanceExecutionEvidence::result($operation, $key)['release'] ?? null;
            if (is_array($release)) {
                return $this->release($operation, $release);
            }
        }

        return null;
    }

    private function selected(Operation $operation): ?DeploymentRelease
    {
        return InstanceDeploymentIntent::provenActivation($operation)
            ?? InstanceDeploymentIntent::storedRelease($this->previous($operation));
    }

    /** @return array{name:string,path:string,commit:string}|null */
    private function previous(Operation $operation): ?array
    {
        return $this->release($operation, InstanceExecutionEvidence::result($operation, 'source.previous')['previous'] ?? null);
    }

    /** @return array{name:string,path:string,commit:string}|null */
    private function release(Operation $operation, mixed $release): ?array
    {
        if ($release === null) {
            return null;
        }
        if (! is_array($release) || ! is_string($release['name'] ?? null) || ! DeploymentRelease::isValidName($release['name'])
            || ! is_string($release['commit'] ?? null) || ! GitCommitSha::isValid($release['commit'])) {
            throw $this->invalidReceipt();
        }

        return ['name' => $release['name'], 'path' => InstanceDeploymentIntent::admitted($operation)['home'].'/releases/'.$release['name'], 'commit' => $release['commit']];
    }

    /** @param array<string,mixed> $result */
    private function projectStep(Operation $operation, string $step, array $result): void
    {
        $this->operations->change($operation, ['state' => [...($operation->state ?? []),
            'steps' => [...ValidatedData::object($operation->state['steps'] ?? []), $step => $result]]]);
    }

    private function recordPhase(Operation $operation, string $key): void
    {
        if (isset(ValidatedData::object($operation->state['deployment_phases'] ?? [])[$key])) {
            return;
        }
        $phaseName = str_starts_with($key, 'source.') ? ($operation->kind === 'instance.rollback' ? 'rollback' : 'source_preparation')
            : (str_starts_with($key, 'php.') ? 'php_refresh' : explode('.', $key)[0]);
        $phase = DeploymentProgressPhase::tryFrom($phaseName);
        if ($phase === null) {
            return;
        }
        $row = InstanceDeployment::query()->findOrFail(ValidatedData::integer($operation->state['deployment_id'] ?? null));
        $collector = DeploymentEventCollector::resume($row->events ?? []);
        $step = str_contains($key, '.') && in_array($phaseName, ['before_activation', 'after_activation'], true)
            ? substr($key, strlen($phaseName) + 1) : null;
        $collector->phase($phase, $step);
        $row->update(['events' => $collector->events()]);
        $this->operations->change($operation, ['state' => [...($operation->state ?? []),
            'deployment_phases' => [...ValidatedData::object($operation->state['deployment_phases'] ?? []), $key => true]]]);
    }

    /**
     * Retire runtime references only for releases the Node proves it removed.
     *
     * @param  array<string,mixed>  $receipt
     * @return array{removed_count:int,in_use_count:int}
     */
    private function cleanup(Operation $operation, array $receipt): array
    {
        $names = [];
        foreach (['removed', 'in_use'] as $field) {
            $list = $receipt[$field] ?? null;
            if (! is_array($list) || ! array_is_list($list)) {
                throw $this->invalidReceipt();
            }
            foreach ($list as $name) {
                if (! is_string($name) || ! DeploymentRelease::isValidName($name) || in_array($name, $names, true)
                    || in_array($name, [$this->candidate($operation)['name'] ?? null, $this->previous($operation)['name'] ?? null], true)) {
                    throw $this->invalidReceipt();
                }
                $names[] = $name;
            }
        }
        $removed = ValidatedData::stringList($receipt['removed']);
        $inUse = ValidatedData::stringList($receipt['in_use']);
        $instance = Instance::query()->findOrFail($operation->target_id);
        $home = InstanceDeploymentIntent::admitted($operation)['home'];
        $stores = $this->vp->stores($instance);
        $this->vp->retire($instance, array_map(static fn (string $name): string => $home.'/releases/'.$name, $removed));
        $this->operations->change($operation, ['state' => [...($operation->state ?? []), 'vp_prune_stores' => $stores]]);
        $row = InstanceDeployment::query()->findOrFail(ValidatedData::integer($operation->state['deployment_id'] ?? null));
        $collector = DeploymentEventCollector::resume($row->events ?? []);
        foreach ($removed as $name) {
            $collector->output(new DeploymentEvent('cleanup', DeploymentOutputStream::Stdout, "Removed old release {$name}.\n"));
        }
        foreach ($inUse as $name) {
            $collector->output(new DeploymentEvent('cleanup', DeploymentOutputStream::Stderr,
                "Kept old release {$name}: a running process still uses it. Stop or restart its remaining processes to free it.\n", important: true));
        }
        $row->update(['events' => $collector->events()]);

        return ['removed_count' => count($removed), 'in_use_count' => count($inUse)];
    }

    /** Known-secret redaction has already run; only bounded deployment events enter history.
     * @param  RunInput  $run
     * @param  array<string,mixed>  $receipt
     */
    private function recordOutput(Operation $operation, array $run, array $receipt): void
    {
        if (! isset($operation->state['deployment_id']) || ! is_array($receipt['output'] ?? null)) {
            return;
        }
        $row = InstanceDeployment::query()->findOrFail(ValidatedData::integer($operation->state['deployment_id'] ?? null));
        $collector = DeploymentEventCollector::resume($row->events ?? []);
        foreach ($receipt['output'] as $chunk) {
            $stream = is_array($chunk) && is_string($chunk['stream'] ?? null) ? DeploymentOutputStream::tryFrom($chunk['stream']) : null;
            if ($stream !== null && is_string($chunk['value'] ?? null)) {
                $collector->output(new DeploymentEvent($run['key'], $stream, $chunk['value']));
            }
        }
        $row->update(['events' => $collector->events()]);
    }

    private function boundary(string $step): DeploymentFailureBoundary
    {
        $phase = explode('.', $step)[0];

        return match ($phase) {
            'source_preparation' => DeploymentFailureBoundary::Preparation,
            'rollback' => DeploymentFailureBoundary::RollbackSelection,
            'environment_sync' => DeploymentFailureBoundary::Environment,
            'before_activation' => DeploymentFailureBoundary::BeforeActivation,
            'activation' => DeploymentFailureBoundary::Activation,
            'php_refresh' => DeploymentFailureBoundary::CacheRefresh,
            default => DeploymentFailureBoundary::AfterActivation,
        };
    }

    /** @param array<string,mixed> $input
     * @return Descriptor
     */
    private function descriptor(string $key, string $step, string $name, array $input, bool $resumable = true, bool $required = true): array
    {
        return compact('key', 'step', 'name', 'input', 'resumable', 'required') + ['transport' => 'instance-deploy-agent'];
    }

    private function invalidReceipt(): ResourceOperationException
    {
        return new ResourceOperationException('deployment.receipt_invalid', 'The Node returned an invalid deployment identity.', 409);
    }

    private function unproven(): ResourceOperationException
    {
        return new ResourceOperationException('operation.completion_unproven', 'Every required deployment step needs a bound successful receipt.', 409);
    }
}
