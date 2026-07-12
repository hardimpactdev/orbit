<?php

declare(strict_types=1);

namespace App\Tools;

final class SeaweedfsTool extends BaseTool
{
    /**
     * @var list<string>
     */
    protected const array SUPPORTED_OPERATING_SYSTEMS = ['linux'];

    protected const ?string REQUIRED_CONTAINER_PROVIDER = 'docker-compatible';

    protected const ?string ISOLATION = 'docker';

    public function slug(): string
    {
        return 'seaweedfs';
    }

    #[\Override]
    public function bootstrapRole(): string
    {
        return 's3';
    }

    #[\Override]
    public function category(): string
    {
        return 'storage';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['credentials', 'safe-fix', 'safe-adopt'];
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'docker',
            'version_command' => 'docker --version',
        ];
    }
}
