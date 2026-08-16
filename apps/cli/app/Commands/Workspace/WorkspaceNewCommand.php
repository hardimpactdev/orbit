<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Commands\Concerns\StreamsGatewayProgress;
use Orbit\Core\Progress\ProgressEventType;

use function Laravel\Prompts\text;

final class WorkspaceNewCommand extends WorkspaceGatewayCommand
{
    use StreamsGatewayProgress;

    #[\Override]
    protected $signature = 'workspace:new
        {name? : Workspace name}
        {--instance= : Owning instance selector (app.instance)}
        {--base=main : Base git ref}
        {--php-version= : PHP version override}
        {--json : Output JSON}
        {--stream-json : Stream newline-delimited JSON progress frames}';

    #[\Override]
    protected $description = 'Create a new workspace intent.';

    public function handle(): int
    {
        $name = $this->resolveName();

        if ($name === null) {
            return $this->failValidation('name', 'Workspace name is required.');
        }

        $nameValidation = $this->validateName($name);

        if ($nameValidation !== null) {
            return $this->failValidation('name', $nameValidation);
        }

        $app = $this->resolveApp();

        if ($app === null) {
            return $this->failValidation(
                'instance',
                'Owning instance is required. Pass --instance= or run from an app directory.',
            );
        }

        return $this->streamProgress(
            '/api/workspaces',
            [
                'name' => $name,
                'instance' => $app,
                'base' => $this->stringOption('base') ?? 'main',
                'php_version' => $this->stringOption('php-version'),
            ],
            fn (ProgressEventType $type, array $payload): int => $this->renderCanonicalProgressTerminalFrame(
                $type,
                $payload,
            ),
        );
    }

    private function resolveName(): ?string
    {
        $name = $this->stringArgument('name');

        if ($name !== null) {
            return $name;
        }

        if ($this->isInteractiveInput()) {
            return trim(text(label: 'Workspace name', required: true));
        }

        return null;
    }

    private function resolveApp(): ?string
    {
        $app = $this->stringOption('instance') ?? $this->instanceFromOrbitMarker();

        if ($app !== null) {
            return $app;
        }

        if ($this->isInteractiveInput()) {
            return trim(text(label: 'Owning instance', required: true));
        }

        return null;
    }

    private function validateName(string $name): ?string
    {
        if ($name === 'main') {
            return "The workspace name 'main' is reserved.";
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name)) {
            return 'Workspace name must match ^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$ (lowercase letters, digits, hyphens; no leading or trailing hyphen).';
        }

        if (strlen($name) > 63) {
            return 'Workspace name must not exceed 63 characters.';
        }

        return null;
    }

    private function isInteractiveInput(): bool
    {
        return $this->allowsInteractiveInput();
    }
}
