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
        {--app= : Parent app name}
        {--base=main : Base git ref}
        {--php-version= : PHP version override}
        {--json : Output JSON}';

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
            return $this->failValidation('app', 'Parent app is required. Pass --app= or run from an app directory.');
        }

        return $this->streamProgress('/api/workspaces', [
            'name' => $name,
            'app' => $app,
            'base' => $this->stringOption('base') ?? 'main',
            'php_version' => $this->stringOption('php-version'),
        ], fn (ProgressEventType $type, array $payload): int => $this->renderProgressTerminalFrame($type, $payload));
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
        $app = $this->stringOption('app') ?? $this->appFromOrbitMarker();

        if ($app !== null) {
            return $app;
        }

        if ($this->isInteractiveInput()) {
            return trim(text(label: 'Parent app', required: true));
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
        return ! $this->wantsJson() && $this->input->isInteractive();
    }
}
