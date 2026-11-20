<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Commands\Concerns\OwnsVersionOption;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Processes\SetProcessArtifactRequest;
use Orbit\Sdk\Responses\Processes\ProcessArtifactResponse;

final class SetProcessArtifactCommand extends ProcessCommand
{
    use OwnsVersionOption;

    #[\Override]
    protected $signature = 'process:artifact:set
        {process : Numeric process ID}
        {--preset= : Fill every field but the version from a role preset, such as cliproxyapi}
        {--repository= : GitHub repository as OWNER/REPO}
        {--asset= : Asset name pattern, with the placeholder version in braces where the version goes}
        {--binary= : Executable path inside the unpacked archive}
        {--checksums= : Checksum asset name, such as checksums.txt}
        {--version-args=* : One argument that makes the binary print its version; repeat for more}
        {--version= : The installed version, when known}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Record the release artifact of a systemd Process.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $processId = $this->positiveId('process', 'Process', 'process.id_invalid');

        if ($processId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $versionArgs = $this->stringListOption('version-args');
        $response = $this->send($connector, new SetProcessArtifactRequest(
            $processId,
            preset: $this->stringOption('preset'),
            repository: $this->stringOption('repository'),
            asset: $this->stringOption('asset'),
            binary: $this->stringOption('binary'),
            checksums: $this->stringOption('checksums'),
            versionArgs: $versionArgs === [] ? null : $versionArgs,
            version: $this->stringOption('version'),
        ), ProcessArtifactResponse::class);

        return $response instanceof ProcessArtifactResponse ? $this->renderArtifact($response) : self::FAILURE;
    }
}
