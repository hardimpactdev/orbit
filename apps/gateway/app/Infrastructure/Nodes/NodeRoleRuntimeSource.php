<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Analytics\PlausibleProcess;
use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\NodeRoleRelocateInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketServerCredentials;
use App\Infrastructure\Metrics\MetricsConfigurationRenderer;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\WebSocket\WebSocketEnvironmentRenderer;
use App\Infrastructure\WebSocket\WebSocketFootprint;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use App\Models\WebSocketRuntime;
use App\Support\ValidatedData;
use InvalidArgumentException;

/** Existing role credential types, rendered only inside the approved private callback. */
final readonly class NodeRoleRuntimeSource implements OperationSecretSource
{
    public function __construct(private SettingRepository $settings, private MetricsConfigurationRenderer $metrics,
        private ServiceMetricsProjection $services, private WebSocketEnvironmentRenderer $websocket,
        private DockerProcessRenderer $docker, private ProcessTargetResolver $targets, private DirectOperationPrivateBindings $bindings) {}

    public function name(): string
    {
        return 'node.role_runtime';
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return ['node'];
    }

    public function ownerId(Operation $operation): ?int
    {
        try {
            $nodeId = ValidatedData::integer($this->snapshot($operation)['node_id']);

            return $this->bindings->ownerNode($operation, 'runtime_material') === $nodeId ? $nodeId : null;
        } catch (ResourceOperationException|InvalidArgumentException) {
            return null;
        }
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $snapshot = $this->snapshot($operation);
        $reference = ['$secret' => $this->name(), 'id' => $id];
        if ($id !== $this->ownerId($operation) || $key !== '' || $parameter !== 'runtime_material'
            || ($operation->input['runtime_material'] ?? null) !== $reference || $operation->status->terminal()) {
            $this->refuse();
        }

        return $this->resolveRoleMaterial($snapshot, $id);
    }

    /** Render existing credential types after the owning parent admits this exact role snapshot.
     * No Operation authority is granted by this lower renderer.
     *
     * @param  array<string,mixed>  $snapshot
     */
    public function resolveRoleMaterial(array $snapshot, int $id): OperationSecretValue
    {
        $reference = ['$secret' => $this->name(), 'id' => $id];
        if (($snapshot['schema_version'] ?? null) !== 1 || ($snapshot['node_id'] ?? null) !== $id
            || ! is_string($snapshot['agent_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $snapshot['agent_sha256']) !== 1
            || ! NodeRole::query()->whereKey($snapshot['assignment_id'] ?? null)->where('node_id', $id)->where('role', $snapshot['role'] ?? null)->exists()) {
            $this->refuse();
        }
        if (! in_array($snapshot['role'] ?? null, ['metrics', 'analytics', 'websocket'], true)) {
            // The swamp role's workers use Node-placed tokens, so its runtime names no private material.
            $runtime = $snapshot['runtime'] ?? null;
            if ($runtime !== null && (($snapshot['role'] ?? null) !== RoleName::Swamp->value || array_key_exists('material', ValidatedData::object($runtime)))) {
                $this->refuse();
            }

            return new OperationSecretValue(null);
        }
        $runtime = ValidatedData::object($snapshot['runtime'] ?? []);
        if (($runtime['node_id'] ?? null) !== $id || ($runtime['assignment_id'] ?? null) !== ($snapshot['assignment_id'] ?? null)
            || ($runtime['role'] ?? null) !== $snapshot['role']
            || ($runtime['agent_sha256'] ?? null) !== $snapshot['agent_sha256']
            || ($runtime['material'] ?? null) !== $reference) {
            $this->refuse();
        }
        $node = Node::query()->findOrFail($id);
        $scope = new SettingScope(SettingScopeType::Node, $id);
        if ($runtime['role'] === RoleName::Metrics->value) {
            $password = $this->settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey);
            if (! is_string($password) || $password === '') {
                $this->refuse();
            }
            $bundle = $this->metrics->render(array_values(array_map(static fn (mixed $target): array => [
                'name' => ValidatedData::string(ValidatedData::object($target)['name']),
                'address' => ValidatedData::string(ValidatedData::object($target)['address']),
            ], is_array($runtime['targets'] ?? null) ? $runtime['targets'] : [])), $password, $this->services->forFleet($node));
            $files = [];
            foreach ($bundle->files as $file) {
                $input = $file->contents->input();
                try {
                    $contents = stream_get_contents($input->stream());
                    if ($contents === false) {
                        $this->refuse();
                    }
                    $files[$file->path] = $contents;
                } finally {
                    $input->close();
                }
            }

            return new OperationSecretValue(['configuration' => $files, 'password' => $password], [$password]);
        }
        if ($runtime['role'] === RoleName::WebSocket->value) {
            $appKey = $this->settings->get($scope, WebSocketFootprint::SettingKeyAppKeyLaravel);
            $registry = WebSocketRuntime::query()->where('node_id', $id)->where('slot', 'default')->where('required', true)->first();
            if (! is_string($appKey) || $appKey === '' || ! $registry instanceof WebSocketRuntime) {
                $this->refuse();
            }
            $environment = $this->websocket->render(new WebSocketServerCredentials($appKey, $registry->id, $registry->registry_token), ValidatedData::integer($runtime['port']));

            return new OperationSecretValue(['websocket_environment' => $environment], [$appKey, $registry->registry_token]);
        }
        $installation = ValidatedData::object($runtime['installation'] ?? []);
        $process = Process::query()->find($installation['process_id'] ?? null);
        if (($installation['runtime'] ?? null) !== 'docker'
            || ($installation['agent_sha256'] ?? null) !== $snapshot['agent_sha256']
            || ! $process instanceof Process || ($installation['name'] ?? null) !== $this->docker->containerName($process)
            || $process->owner_type !== Node::class || $process->owner_id !== $id
            || $process->name !== PlausibleProcess::NAME || $process->runtime !== ProcessRuntime::Docker
            || ($installation['spec'] ?? null) !== $this->docker->specHash($process, $this->targets->forInstallation($process))) {
            $this->refuse();
        }

        return new OperationSecretValue(['installation' => ['runtime' => 'docker', 'spec' => $installation['spec'],
            'definition' => $this->docker->containerInput($process, $this->targets->forInstallation($process))]]);
    }

    /** @return array<string,mixed> */
    private function snapshot(Operation $operation): array
    {
        return $operation->kind === 'node.role_relocate'
            ? ValidatedData::object(NodeRoleRelocateInput::bound($operation)['target']) : NodeRoleDirectInput::bound($operation);
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.execution_forbidden', 'Runtime material must belong to this admitted Node role.', 422);
    }
}
