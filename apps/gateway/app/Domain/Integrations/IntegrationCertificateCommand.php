<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Operations\OperationGatewayCommand;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class IntegrationCertificateCommand implements OperationGatewayCommand
{
    public function __construct(private CaddySiteCertificates $certificates) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['slug'],
            'properties' => ['slug' => ['type' => 'string', 'enum' => ['ai-router', 'analytics', 'metrics']]]];
    }

    public function run(Operation $operation, array $input): array
    {
        $this->certificates->record(ValidatedData::integer($operation->node_id), ValidatedData::string($input['slug']));

        return ['recorded' => true];
    }
}
