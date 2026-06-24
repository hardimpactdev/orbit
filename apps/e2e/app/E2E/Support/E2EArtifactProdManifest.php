<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Support\Facades\Process;

final readonly class E2EArtifactProdManifest
{
    public const string GatewayImageArchive = 'orbit-gateway-current.tar';

    public const string WebSocketImageArchive = 'orbit-reverb-current.tar';

    public const string BinaryArtifactDirectory = 'apps/cli/builds/e2e-artifact-prod';

    public function __construct(
        public string $version,
        public string $gatewayImage,
        public ?string $gatewayImageId,
        public string $gatewayImageArchive,
        public ?string $cliBinary,
        public ?string $cliBinarySha256,
    ) {}

    public static function binaryArtifactDirectory(): string
    {
        return repo_path(self::BinaryArtifactDirectory);
    }

    public static function binaryArtifactPath(): string
    {
        return OrbitCliBinaryBundle::bundledBinaryPath(self::binaryArtifactDirectory());
    }

    public static function fromPreparedDockerRuntime(
        string $gatewayImage,
        ?string $gatewayImageId,
        ?string $cliBinary,
    ): self {
        return new self(
            version: self::currentVersion(),
            gatewayImage: $gatewayImage,
            gatewayImageId: $gatewayImageId,
            gatewayImageArchive: self::GatewayImageArchive,
            cliBinary: $cliBinary,
            cliBinarySha256: self::sha256($cliBinary),
        );
    }

    /**
     * @return array{
     *     version: string,
     *     gateway_image: string,
     *     gateway_image_id: string|null,
     *     gateway_image_archive: string,
     *     cli_binary: string|null,
     *     cli_binary_sha256: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'gateway_image' => $this->gatewayImage,
            'gateway_image_id' => $this->gatewayImageId,
            'gateway_image_archive' => $this->gatewayImageArchive,
            'cli_binary' => $this->cliBinary,
            'cli_binary_sha256' => $this->cliBinarySha256,
        ];
    }

    private static function currentVersion(): string
    {
        $result = Process::timeout(30)
            ->path(repo_path())
            ->run('git rev-parse --short HEAD');

        if (! $result->successful()) {
            return 'unknown';
        }

        $version = trim($result->output());

        return $version !== '' ? $version : 'unknown';
    }

    private static function sha256(?string $path): ?string
    {
        if ($path === null || ! is_file($path)) {
            return null;
        }

        $sha256 = hash_file('sha256', $path);

        return is_string($sha256) ? $sha256 : null;
    }
}
