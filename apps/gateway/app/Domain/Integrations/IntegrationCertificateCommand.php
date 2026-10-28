<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Operations\OperationGatewayCommand;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Models\Operation;

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
        $this->certificates->record($operation->node_id, $input['slug']);
        return ['recorded' => true];
    }
}
