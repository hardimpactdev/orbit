<?php

declare(strict_types=1);

namespace App\Services\S3;

use App\Data\Nodes\RoleSettings\S3RoleSettings;
use App\Models\Node;
use App\Services\Runtime\OrbitContainerNames;
use InvalidArgumentException;
use RuntimeException;

class S3RuntimeContainerRenderer
{
    public function __construct(
        private readonly OrbitContainerNames $names,
    ) {}

    public function render(
        Node $node,
        S3RoleSettings $settings,
        string $image = S3RuntimeContainer::Image,
    ): S3RuntimeContainer {
        $wireGuardAddress = $this->wireGuardAddress($node);
        $dataPath = $this->normalizeDataPath($settings->dataPath);

        return new S3RuntimeContainer(
            name: S3RuntimeContainer::ContainerName,
            image: $image,
            network: $this->names->network(),
            restartPolicy: 'unless-stopped',
            wireGuardAddress: $wireGuardAddress,
            mounts: [
                [
                    'source' => $dataPath,
                    'target' => S3RuntimeContainer::DataTarget,
                    'read_only' => false,
                ],
            ],
        );
    }

    private function wireGuardAddress(Node $node): string
    {
        $wireGuardAddress = trim((string) $node->wireguard_address);

        if ($wireGuardAddress === '') {
            throw new RuntimeException('The s3 role requires a WireGuard address before runtime config can be rendered.');
        }

        return $wireGuardAddress;
    }

    private function normalizeDataPath(string $dataPath): string
    {
        $dataPath = trim($dataPath);

        if ($dataPath === '') {
            throw new InvalidArgumentException('The s3 runtime data path cannot be empty.');
        }

        if ($dataPath === '/') {
            return $dataPath;
        }

        return rtrim($dataPath, '/');
    }
}
