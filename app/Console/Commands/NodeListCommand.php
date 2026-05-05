<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\ListNodesRequest;
use App\Http\Gateway\Responses\Nodes\NodeListResponse;
use App\Models\Node;
use App\Services\Nodes\NodesDoctorSummary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

#[Signature('node:list
    {--role= : Filter by role (gateway|app|control)}
    {--environment= : Filter by environment (development|production)}
    {--doctor : Include node doctor checks and summaries}
    {--json : Output as JSON}')]
#[Description('List nodes registered in the gateway registry')]
class NodeListCommand extends Command
{
    private const array VALID_ROLES = ['gateway', 'app', 'control'];

    private const array VALID_ENVIRONMENTS = ['development', 'production'];

    public function handle(NodesDoctorSummary $doctorSummary): int
    {
        $role = $this->option('role');
        $environment = $this->option('environment');
        $doctor = $this->wantsDoctor();

        if (is_string($role) && $role !== '') {
            if (! in_array($role, self::VALID_ROLES, true)) {
                return $this->failValidation(
                    field: 'role',
                    value: $role,
                    allowed: self::VALID_ROLES,
                );
            }
        }

        if (is_string($environment) && $environment !== '') {
            if (! in_array($environment, self::VALID_ENVIRONMENTS, true)) {
                return $this->failValidation(
                    field: 'environment',
                    value: $environment,
                    allowed: self::VALID_ENVIRONMENTS,
                );
            }
        }

        try {
            $result = $this->fetchNodes(
                role: is_string($role) && $role !== '' ? $role : null,
                environment: is_string($environment) && $environment !== '' ? $environment : null,
                doctor: $doctor,
                doctorSummary: $doctorSummary,
            );
        } catch (Throwable) {
            return $this->failForwarding();
        }

        if ($result instanceof GatewayApiException) {
            return $this->failGatewayException($result);
        }

        $payload = ['nodes' => $result['nodes']];

        if ($this->wantsJson()) {
            return $this->jsonSuccess($payload, $result['meta']);
        }

        $doctorMeta = $result['meta']['doctor'] ?? null;

        $this->renderHuman(
            nodes: $result['nodes'],
            doctor: is_array($doctorMeta) ? $doctorMeta : null,
        );

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     nodes: list<array<string, mixed>>,
     *     meta: array<string, mixed>,
     * }|GatewayApiException
     */
    private function fetchNodes(?string $role, ?string $environment, bool $doctor, NodesDoctorSummary $doctorSummary): array|GatewayApiException
    {
        if ($this->isGatewayCaller()) {
            return $this->fetchLocalNodes(
                role: $role,
                environment: $environment,
                doctor: $doctor,
                doctorSummary: $doctorSummary,
            );
        }

        try {
            $dto = app(GatewayConnector::class)
                ->send(new ListNodesRequest(role: $role, environment: $environment, doctor: $doctor))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        }

        /** @var NodeListResponse $dto */
        return [
            'nodes' => $dto->nodes,
            'meta' => $dto->meta,
        ];
    }

    private function isGatewayCaller(): bool
    {
        return $this->callerRole() === 'gateway';
    }

    private function callerRole(): string
    {
        $localRole = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('role');

        if (! is_string($localRole) || $localRole === '') {
            return 'control';
        }

        if (! in_array($localRole, ['gateway', 'app', 'control'], true)) {
            return 'unknown';
        }

        return $localRole;
    }

    /**
     * @return array{
     *     nodes: list<array<string, mixed>>,
     *     meta: array<string, mixed>,
     * }
     */
    private function fetchLocalNodes(?string $role, ?string $environment, bool $doctor, NodesDoctorSummary $doctorSummary): array
    {
        $nodes = $this->fetchLocalNodeModels($role, $environment);

        $meta = [];

        if ($doctor) {
            $meta['doctor'] = $doctorSummary->forNodes($nodes);
        }

        return [
            'nodes' => $nodes->map(fn (Node $node): array => $this->nodePayload($node))->all(),
            'meta' => $meta,
        ];
    }

    /**
     * @return Collection<int, Node>
     */
    private function fetchLocalNodeModels(?string $role, ?string $environment): Collection
    {
        $query = Node::query()
            ->orderBy('role')
            ->orderBy('name');

        if ($role !== null) {
            $query->where('role', $role);
        }

        if ($environment !== null) {
            $query->where('environment', $environment);
        }

        return $query->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function nodePayload(Node $node): array
    {
        return [
            'name' => $node->name,
            'role' => $node->role,
            'environment' => $node->role === 'app' ? $node->environment : null,
            'platform' => $node->platform ?? 'unknown',
            'status' => $node->status,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>|null  $doctor
     */
    private function renderHuman(array $nodes, ?array $doctor): void
    {
        if ($nodes === []) {
            $this->line('No nodes found.');
            $this->renderDoctorSummary($doctor);

            return;
        }

        $this->table(
            ['ROLE', 'NAME', 'ENVIRONMENT', 'PLATFORM', 'STATUS'],
            array_map(fn (array $node): array => [
                $node['role'],
                $node['name'],
                $node['environment'] ?? '—',
                $node['platform'],
                $node['status'],
            ], $nodes),
        );

        $this->renderDoctorSummary($doctor);
    }

    /**
     * @param  array<string, mixed>|null  $doctor
     */
    private function renderDoctorSummary(?array $doctor): void
    {
        if ($doctor === null) {
            return;
        }

        $this->newLine();

        $issues = (int) ($doctor['issues'] ?? 0);

        if ($issues === 0) {
            $this->line('Doctor: All nodes healthy.');

            return;
        }

        $issueLabel = $issues === 1 ? 'issue' : 'issues';

        $this->line("Doctor: {$issues} {$issueLabel} found.");

        $failures = $doctor['failures'] ?? [];

        if (! is_array($failures)) {
            $failures = [];
        }

        foreach ($failures as $failure) {
            if (! is_array($failure)) {
                continue;
            }

            $node = $failure['node'] ?? 'unknown';
            $message = $failure['message'] ?? 'Node doctor issue detected.';
            $code = $failure['code'] ?? 'node.unknown';

            $this->line("  {$node}: {$message} ({$code})");
        }

        $this->line('  Run `orbit doctor --family=node --fix` to repair.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function jsonSuccess(array $data, array $meta = []): int
    {
        $success = [
            'data' => $data,
        ];

        if ($meta !== []) {
            $success['meta'] = $meta;
        }

        $this->line(json_encode([
            'success' => $success,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function failValidation(string $field, string $value, array $allowed): int
    {
        $message = "Invalid value for --{$field}: '{$value}'. Allowed values: ".implode(', ', $allowed).'.';

        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => $message,
                    'meta' => [
                        'field' => $field,
                        'value' => $value,
                        'allowed' => $allowed,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    private function wantsDoctor(): bool
    {
        return (bool) $this->option('doctor');
    }

    private function failForwarding(): int
    {
        return $this->failGatewayError(
            code: 'gateway_unavailable',
            message: 'Gateway connection is required to list nodes.',
            meta: [],
        );
    }

    private function failGatewayException(GatewayApiException $exception): int
    {
        return $this->failGatewayError(
            code: $exception->errorCode() ?? 'gateway_unavailable',
            message: $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'Gateway connection is required to list nodes.',
            meta: $exception->errorMeta(),
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failGatewayError(string $code, string $message, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => empty($meta) ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }
}
