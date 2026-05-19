<?php

declare(strict_types=1);

namespace App\Tools;

final class PhpTool extends BaseTool
{
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

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'php',
            'version_command' => 'php -r "echo PHP_VERSION;"',
        ];
    }
}
