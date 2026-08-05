<?php

declare(strict_types=1);

namespace App\Tools;

use App\Services\Php\PhpRuntimeCatalog;

final class PhpTool extends BaseTool
{
    /**
     * @var list<string>
     */
    protected const array SUPPORTED_OPERATING_SYSTEMS = ['linux', 'macos'];

    protected const ?string REQUIRED_CONTAINER_PROVIDER = 'docker-compatible';

    protected const ?string ISOLATION = 'docker';

    public function slug(): string
    {
        return 'php';
    }

    #[\Override]
    public function category(): string
    {
        return 'runtime';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'remove', 'update'];
    }

    /**
     * Image capability rows do not own exclusive host packages. Removal clears
     * gateway registry intent only; shared FrankenPHP images used by apps remain.
     */
    #[\Override]
    public function removeScript(array $config = []): string
    {
        return <<<'BASH'
            #!/usr/bin/env bash
            # orbit remove php
            # Registry cleanup only: do not delete shared runtime images.
            set -euo pipefail
            true
            BASH;
    }

    #[\Override]
    public function probeMetadata(): array
    {
        $catalog = new PhpRuntimeCatalog;

        return [
            'probe' => 'docker_images',
            'images' => $catalog->supportedImages(),
        ];
    }
}
