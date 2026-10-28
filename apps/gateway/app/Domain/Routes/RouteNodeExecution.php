<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Exact public inputs rendered by the Gateway; Swamp cannot supply another Caddyfile. */
final readonly class RouteNodeExecution implements OperationFamilyExecution
{
    public function context(Operation $operation): array
    {
        $spec = $this->spec($operation);
        $descriptors = [['key' => 'prepare', 'step' => 'prepare', 'transport' => 'gateway', 'name' => $spec['prepare'],
            'input' => ['reason' => $operation->input['reason']], 'resumable' => true, 'required' => true]];
        if (is_array($operation->state[$spec['plan']] ?? null)) {
            $descriptors[] = ['key' => $spec['step'], 'step' => $spec['step'], 'transport' => $spec['transport'], 'name' => $spec['command'],
                'input' => $this->plan($operation), 'resumable' => true, 'required' => true];
        }

        return ['descriptors' => $descriptors];
    }

    public function input(Operation $operation, array $run): array
    {
        $spec = $this->spec($operation);
        return match ($run['name']) {
            $spec['prepare'] => ['reason' => $operation->input['reason']],
            $spec['command'] => $this->plan($operation),
            default => throw new ResourceOperationException('operation.execution_forbidden', 'This publication command is not admitted.', 422),
        };
    }

    public function beforeRun(Operation $operation, array $run): void {}

    public function received(Operation $operation, array $run, array $receipt): array
    {
        $spec = $this->spec($operation);
        if ($run['name'] !== $spec['command'] || isset($receipt['error_code'])) {
            return $receipt;
        }
        if ($operation->kind === 'route.private_dns_publish') {
            if (($receipt['outcome'] ?? null) === 'refused' && is_string($receipt['data']['error_code'] ?? null)) {
                return ['error_code' => $receipt['data']['error_code']];
            }
            if (($receipt['outcome'] ?? null) !== 'published' || ($receipt['data']['ready'] ?? null) !== true) {
                throw new ResourceOperationException('private_dns.result_invalid', 'Private DNS returned no publication receipt.', 409);
            }
        } elseif (! in_array($receipt['result'] ?? null, ['published', 'unchanged'], true)) {
            throw new ResourceOperationException('caddy.result_invalid', 'Caddy returned no publication receipt.', 409);
        }
        $receipt['ready'] = true;

        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function plan(Operation $operation): array
    {
        $spec = $this->spec($operation);
        if (! is_array($operation->state[$spec['plan']] ?? null)) {
            throw new ResourceOperationException('route.plan_missing', 'Prepare the Gateway render before publication.', 409);
        }

        return $operation->state[$spec['plan']];
    }

    /** @return array{prepare: string, plan: string, step: string, transport: string, command: string} */
    private function spec(Operation $operation): array
    {
        return match ($operation->kind) {
            'route.caddy_converge' => ['prepare' => 'route.caddy-prepare', 'plan' => 'caddy_plan', 'step' => 'caddy-publish', 'transport' => 'route-caddy', 'command' => 'caddy.publish'],
            'route.private_dns_publish' => ['prepare' => 'route.private-dns-prepare', 'plan' => 'private_dns_plan', 'step' => 'private-dns-publish', 'transport' => 'route-dns', 'command' => 'route.publish-dns'],
            default => throw new ResourceOperationException('operation.execution_forbidden', 'This kind is not a Node publication.', 422),
        };
    }
}
