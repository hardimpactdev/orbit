<?php

declare(strict_types=1);

namespace App\Commands\App;

use Orbit\Core\Progress\ProgressEventType;

final class AppNewCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'app:new
        {name? : App name}
        {--node= : Target app node}
        {--repo= : Repository to clone}
        {--root=public : Document root relative to app path}
        {--php-version=8.5 : PHP version}
        {--domain= : Production domain}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Create a new app on an app node.';

    public function handle(): int
    {
        $name = $this->stringArgument('name');
        $node = $this->stringOption('node');

        if ($name === null) {
            return $this->failValidation('name', 'App name is required.');
        }

        if ($node === null) {
            return $this->failValidation('node', 'The --node option is required.');
        }

        return $this->streamProgress(
            '/api/apps',
            [
                'name' => $name,
                'node' => $node,
                'repository' => $this->stringOption('repo'),
                'root' => $this->stringOption('root') ?? 'public',
                'php_version' => $this->stringOption('php-version') ?? '8.5',
                'domain' => $this->stringOption('domain'),
            ],
            fn (ProgressEventType $type, array $payload): int => $this->renderProgressTerminalFrame($type, $payload),
        );
    }
}
