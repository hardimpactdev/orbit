<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class CertificateChildAdmission implements OperationChildAdmission
{
    public function __construct(private StartCertificateOperationAction $certificates) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        $node = Node::query()->findOrFail(ValidatedData::integer($intent['node_id']));
        $scope = ValidatedData::string($intent['input']['scope']);

        return match ($intent['kind']) {
            'certificate.converge' => $this->certificates->converge($node, $scope, ValidatedData::string($intent['input']['domain']), $parent->request_id, $parent->triggered_by),
            'certificate.remove' => $this->certificates->remove($node, $scope, $parent->request_id, $parent->triggered_by),
            default => throw new ResourceOperationException('operation.execution_forbidden', 'This is not an owned certificate child kind.', 422),
        };
    }
}
