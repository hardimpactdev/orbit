<?php

declare(strict_types=1);

namespace App\Domain\Certificates;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Keeps public CSR bytes usable without weakening the common PEM redactor. */
final readonly class CertificateExecution implements OperationFamilyExecution
{
    public function context(Operation $operation): array
    {
        $commands = $operation->kind === 'certificate.remove'
            ? [['certificate-remove', 'agent', 'certificate.remove-leaf']]
            : [['certificate-request', 'certificate-request', 'certificate.request-leaf']];
        $request = RouteExecutionProof::result($operation, 'certificate-request');
        if (($request['state'] ?? null) === 'request') {
            $commands[] = ['certificate-sign', 'gateway', 'certificate.sign-leaf'];
            if ((data_get($operation->state, 'executor_runs.certificate-sign.result.signed', false)) === true) {
                $commands[] = ['certificate-publish', 'agent', 'certificate.publish-leaf'];
            }
        }
        if (($request['state'] ?? null) === 'current' || (is_array(data_get($operation->state, 'executor_runs.certificate-publish.result'))
            && ! isset(RouteExecutionProof::result($operation, 'certificate-publish')['error_code']))) {
            $commands[] = ['certificate-inspect', 'agent', 'certificate.inspect-leaf'];
        }
        $descriptors = [];
        foreach ($commands as [$key, $transport, $name]) {
            $run = ['worker' => $operation->worker ?? '', 'key' => $key, 'transport' => $transport, 'name' => $name, 'input' => []];
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
                'request_base64' => data_get($operation->state, 'executor_runs.certificate-request.result.request_base64')],
            'certificate.publish-leaf' => [...$leaf,
                'certificate' => ['$secret' => 'certificate.leaf', 'id' => $operation->id],
                'root_certificate' => ['$secret' => 'certificate.leaf', 'id' => $operation->id]],
            'certificate.inspect-leaf', 'certificate.remove-leaf' => Arr::only($operation->input, ['scope', 'home']),
            default => throw new ResourceOperationException('operation.execution_forbidden', 'Unknown certificate command.', 422),
        };
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        RouteExecutionProof::preceding($operation, $this->context($operation)['descriptors'], $run['key']);
        if ($run['name'] === 'certificate.remove-leaf') {
            $scope = ValidatedData::string($operation->input['scope']);
            $node = Node::query()->findOrFail($operation->node_id);
            if (app(DevelopmentSiteRepository::class)->forNode($node)->contains(
                static fn (DevelopmentSite $site): bool => $site->loadsCertificate($scope))) {
                throw new ResourceOperationException('app-dev.certificate_in_use', 'A stored Caddy site still uses this certificate.', 409);
            }
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        return $operation->kind === 'certificate.converge'
            ? ['key' => 'certificate-publish', 'transport' => 'agent', 'name' => 'certificate.publish-leaf', 'result' => []]
            : null;
    }
}
