<?php

declare(strict_types=1);

namespace App\Domain\Certificates;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Illuminate\Support\Arr;

/** Keeps public CSR bytes usable without weakening the common PEM redactor. */
final readonly class CertificateExecution implements OperationFamilyExecution
{
    public function context(Operation $operation): array
    {
        $commands = $operation->kind === 'certificate.remove'
            ? [['certificate-remove', 'agent', 'certificate.remove-leaf']]
            : [['certificate-request', 'agent', 'certificate.request-leaf']];
        $runs = $operation->state['executor_runs'] ?? [];
        $request = $runs['certificate-request']['result'] ?? [];
        if (($request['state'] ?? null) === 'request') {
            $commands[] = ['certificate-sign', 'gateway', 'certificate.sign-leaf'];
            if (($runs['certificate-sign']['result']['signed'] ?? false) === true) {
                $commands[] = ['certificate-publish', 'agent', 'certificate.publish-leaf'];
            }
        }
        if (($request['state'] ?? null) === 'current' || (is_array($runs['certificate-publish']['result'] ?? null)
            && ! isset($runs['certificate-publish']['result']['error_code']))) {
            $commands[] = ['certificate-inspect', 'agent', 'certificate.inspect-leaf'];
        }
        $descriptors = [];
        foreach ($commands as [$key, $transport, $name]) {
            $run = ['key' => $key, 'transport' => $transport, 'name' => $name];
            $descriptors[] = [...$run, 'step' => $key, 'input' => $this->input($operation, $run), 'resumable' => true, 'required' => true];
        }

        return ['descriptors' => $descriptors];
    }

    public function input(Operation $operation, array $run): array
    {
        $leaf = Arr::only($operation->input, ['scope', 'domain', 'version', 'root_hash', 'user', 'home']);
        return match ($run['name']) {
            'certificate.request-leaf' => $leaf,
            'certificate.sign-leaf' => ['domain' => $operation->input['domain'],
                'request_base64' => $operation->state['executor_runs']['certificate-request']['result']['request_base64'] ?? null],
            'certificate.publish-leaf' => [...$leaf,
                'certificate' => ['$secret' => 'certificate.leaf', 'id' => $operation->id],
                'root_certificate' => ['$secret' => 'certificate.leaf', 'id' => $operation->id]],
            'certificate.inspect-leaf', 'certificate.remove-leaf' => Arr::only($operation->input, ['scope', 'home']),
            default => throw new ResourceOperationException('operation.execution_forbidden', 'Unknown certificate command.', 422),
        };
    }

    public function beforeRun(Operation $operation, array $run): void {}

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if ($run['name'] === 'certificate.request-leaf' && ($receipt['state'] ?? null) === 'request') {
            $request = $receipt['request'] ?? null;
            if (! is_string($request) || ! str_contains($request, '-----BEGIN CERTIFICATE REQUEST-----')) {
                throw new ResourceOperationException('certificate.request_invalid', 'The Node returned no public signing request.', 409);
            }
            $receipt['request_base64'] = base64_encode($request);
            unset($receipt['request']);
        }

        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }
}
