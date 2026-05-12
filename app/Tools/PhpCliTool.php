<?php

declare(strict_types=1);

namespace App\Tools;

final class PhpCliTool extends BaseTool
{
    public function slug(): string
    {
        return 'php-cli';
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
