<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Fleet\CliConvergePlan;
use App\Domain\Metrics\MetricsFleetRuntimeMaterial;
use App\Domain\Nodes\NodeConvergeInput;
use App\Domain\Nodes\NodeEnrollmentCandidate;
use App\Domain\Nodes\NodeEnrollmentInput;
use App\Domain\Nodes\NodeEnrollmentPolicy;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeProvisioningLockException;
use App\Domain\Nodes\NodeRoleCertificateInput;
use App\Domain\Nodes\NodeRoleConvergeInput;
use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Routes\NativeRouteNodeMutationInput;
use App\Domain\Routes\NativeRouteNodeRender;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeRoleRuntimeSource;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Reserve real records and capture one complete native run; admission performs no host action.
 * @phpstan-import-type CertificatePlan from NodeRoleCertificateInput
 */
final readonly class AdmitNodeEnrollmentOperationAction
{
    public const array IdentityFields = ['status', 'cluster_id', 'platform', 'architecture', 'tld', 'user', 'public_ssh_host', 'public_ssh_port',
        'wireguard_ip', 'lan_ip', 'wireguard_endpoint_override', 'dns_server_override', 'failed_step', 'error_code',
        'ssh_host_key_type', 'ssh_host_key', 'ssh_host_fingerprint', 'wireguard_public_key', 'ssh_jump_node_id'];

    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency,
        private NodeProvisioningLock $lock, private NodeEnrollmentPolicy $policy, private NodeEnrollmentInput $capture,
        private AssignRoleAction $assign, private NodeRoleRuntimeInput $runtimes, private NodeRoleConvergeInput $roles,
        private NativeRouteNodeMutationInput $routes, private UpdateNodeSettingsAction $settings, private NodeSettingsNormalizer $normalizer,
        private NodeRoleRuntimeSource $roleSecrets, private SwampCommandStep $copies,
        private NodeConvergeInput $footprints, private RouteRenderContext $render, private CliConvergePlan $cli,
        private MetricsFleetRuntimeMaterial $metricsSecrets) {}

    public function execute(ProvisionNodeData $data, ?string $requestId = null): Operation
    {
        if (($existing = $this->idempotency->replay('node.add')) !== null) {
            return $existing;
        }
        try {
            return $this->lock->run($data->name, fn (): Operation => DB::transaction(fn (): Operation => $this->admit($data, $requestId)));
        } catch (NodeProvisioningLockException $exception) {
            throw $exception->toBusyException();
        }
    }

    private function admit(ProvisionNodeData $data, ?string $requestId): Operation
    {
        $actual = Node::query()->where('name', $data->name)->lockForUpdate()->first() ?? new Node(['name' => $data->name]);
        if ($actual->id === 209 || ($actual->exists && ! in_array($actual->status, [LifecycleStatus::Active, LifecycleStatus::Failed, LifecycleStatus::Provisioning], true))) {
            throw new ResourceOperationException('node.executor_dependency', 'Enrollment requires a target separate from the active209 execution host.', 409);
        }
        $spec = $this->policy->prepare($actual, $data);
        $existed = $actual->exists;
        $prior = $existed ? $actual->only(self::IdentityFields) : null;
        if ($prior !== null) {
            $prior['status'] = $actual->status->value;
        }
        $identity = $spec['candidate'];
        if (! $existed) {
            $actual->fill([...$identity, 'status' => LifecycleStatus::Provisioning])->save();
        }
        $candidate = new NodeEnrollmentCandidate($actual->id, $data->name, ValidatedData::string($identity['user']),
            ValidatedData::string($identity['platform']), ValidatedData::nullableString($identity['architecture']),
            ValidatedData::string($identity['wireguard_ip']), ValidatedData::string($identity['public_ssh_host']),
            ValidatedData::integer($identity['public_ssh_port']), $identity['cluster_id'] === null ? null : ValidatedData::integer($identity['cluster_id']),
            ValidatedData::nullableString($identity['tld']), ValidatedData::nullableString($identity['lan_ip']),
            ValidatedData::nullableString($identity['wireguard_endpoint_override']), ValidatedData::nullableString($identity['dns_server_override']));
        $effective = $data->settingsProvided ? $data->settings : $this->normalizer->fromStored($actual->settings);
        $settings = $this->settings->captureForEnrollment($actual, $candidate, $effective,
            in_array(RoleName::AppDev, $data->roles, true) || $actual->roles()->where('role', RoleName::AppDev)->where('status', LifecycleStatus::Active)->exists());
        $participants = [$actual->id, 209];
        $reserved = $roleInputs = $bindings = $assignments = [];
        $roleCertificates = ['leaves' => [], 'withdrawals' => []];
        $requested = $data->roles;
        usort($requested, static fn (RoleName $left, RoleName $right): int => ($left === RoleName::Vpn ? 0 : 1) <=> ($right === RoleName::Vpn ? 0 : 1));
        foreach ($requested as $role) {
            $previous = $actual->roles()->where('role', $role)->first();
            $assignment = $this->assign->execute($actual, $role);
            $reserved[] = ['assignment_id' => $assignment->id, 'created' => $previous === null];
            $this->runtimes->prepare($actual, $assignment, $candidate);
            $assignments[] = $assignment;
        }
        $actual->unsetRelation('roles');
        $intent = $this->capture->capture($actual, $candidate, $spec['mode'], $spec['bootstrap_user'], $spec['fingerprint'],
            $spec['requested_architecture'], $data->roles);
        $materials = ['observer_hash' => new OperationSecretValue($actual->agent_secret_hash)];
        foreach ($assignments as $assignment) {
            $role = $assignment->role;
            $captured = $this->roles->capture($actual, $assignment, $candidate, $settings['settings'] ?? []);
            $roleIntent = ValidatedData::object($captured['intent']);
            $material = $this->roleSecrets->resolveRoleMaterial($roleIntent, $actual->id, $candidate);
            $field = 'role_runtime_'.$assignment->id;
            $materials[$field] = $material;
            $bindings[$role->value] = ['runtime_material' => $field];
            if (is_array($roleIntent['metrics_fleet'] ?? null)) {
                $fleet = ValidatedData::object($roleIntent['metrics_fleet']);
                $ownerId = ValidatedData::integer($fleet['metrics_node_id']);
                $fleetMaterial = $this->metricsSecrets->resolveFleetMaterial($fleet, $ownerId, $ownerId === $actual->id ? $candidate : null);
                $metricsField = 'role_metrics_'.$assignment->id;
                $materials[$metricsField] = $fleetMaterial;
                $bindings[$role->value]['metrics_runtime'] = $metricsField;
            }
            $roleInputs[] = $roleIntent;
            $participants = [...$participants, ...ValidatedData::integerList($captured['participant_node_ids'])];
            $plan = $this->certificatePlan($roleIntent['certificates']);
            $roleCertificates['leaves'] = [...$roleCertificates['leaves'], ...$plan['leaves']];
            $roleCertificates['withdrawals'] = [...$roleCertificates['withdrawals'], ...$plan['withdrawals']];
        }
        $route = $spec['mode'] === 'macos' ? null : $this->routes->capture($actual,
            new NativeRouteNodeRender($actual->id, $candidate->wireguardIp, $candidate->tld, roleCertificates: $roleCertificates), $candidate->clusterId);
        $hub = $intent['hub'] === null ? null : ValidatedData::object($intent['hub']);
        $participants = array_values(array_unique([...$participants, ...($route['participant_node_ids'] ?? []),
            ...($hub === null ? [] : [ValidatedData::integer($hub['hub_id'])])]));
        sort($participants);
        $certificates = array_values(array_unique([...$roleCertificates['leaves'], ...($route['certificates'] ?? [])], SORT_REGULAR));
        $footprint = $spec['mode'] === 'macos' ? null : $this->render->renderRoleCertificates(
            $roleCertificates,
            fn (): array => $this->footprints->captureFootprint($actual, $candidate, $settings['settings'] ?? []));
        $nonce = bin2hex(random_bytes(8));
        $secrets = [];
        foreach ($materials as $field => $material) {
            $secrets[$field] = $this->copies->executionCopy('orbit-control-inputs', 'node-enrollment-'.$nonce.'-'.$field,
                json_encode($material->value, JSON_THROW_ON_ERROR));
        }
        foreach ($bindings as $role => $fields) {
            foreach ($fields as $field => $reference) {
                $bindings[$role][$field] = $secrets[$reference];
            }
        }
        $intent += ['roles' => $roleInputs, 'role_bindings' => $bindings, 'settings' => $settings,
            'role_certificates' => $roleCertificates, 'cli' => $this->cli->input($actual, $candidate),
            'route_mutations' => $route['route_mutations'] ?? null, 'footprint' => $footprint];

        return $this->operations->execute('node.add', 'node', $actual->id, $actual->id,
            ['intent' => $intent, 'execution_secrets' => [...$secrets, ...($route['execution_secrets'] ?? [])],
                'certificates' => $certificates, 'participant_node_ids' => $participants,
                'enrollment' => ['existed' => $existed, 'prior_state' => $prior, 'candidate' => $identity,
                    'reserved_roles' => $reserved, 'settings_provided' => $data->settingsProvided, 'settings' => $settings['settings']]],
            'operator', $requestId);
    }

    /** @return CertificatePlan */
    private function certificatePlan(mixed $raw): array
    {
        $raw = ValidatedData::object($raw);
        if (! is_array($raw['leaves']) || ! array_is_list($raw['leaves'])
            || ! is_array($raw['withdrawals']) || ! array_is_list($raw['withdrawals'])) {
            throw new \InvalidArgumentException('Role certificate capture must provide admitted leaf and withdrawal lists.');
        }
        $plan = ['leaves' => [], 'withdrawals' => []];
        foreach ($raw['leaves'] as $value) {
            $leaf = ValidatedData::object($value);
            $plan['leaves'][] = ['node_id' => ValidatedData::integer($leaf['node_id']), 'scope' => ValidatedData::string($leaf['scope']),
                'domain' => ValidatedData::string($leaf['domain']), 'version' => ValidatedData::string($leaf['version']),
                'root_hash' => ValidatedData::string($leaf['root_hash'])];
        }
        foreach ($raw['withdrawals'] as $value) {
            $withdrawal = ValidatedData::object($value);
            $plan['withdrawals'][] = ['node_id' => ValidatedData::integer($withdrawal['node_id']), 'scope' => ValidatedData::string($withdrawal['scope'])];
        }

        return $plan;
    }
}
