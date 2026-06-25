<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\Concerns\PromptsForGatewayRegistryEntities;
use App\Exceptions\OrbitConfigStoreException;
use App\Services\OrbitConfigStore;
use Orbit\Core\Progress\ProgressEventType;

use function Laravel\Prompts\text;

final class AppNewCommand extends AppGatewayCommand
{
    use PromptsForGatewayRegistryEntities;

    #[\Override]
    protected $signature = 'app:new
        {name? : App name}
        {--node= : Target app node}
        {--repo= : Repository to clone}
        {--root=public : Document root relative to app path}
        {--php-version=8.5 : PHP version}
        {--domain= : Production domain}
        {--runtime-proxy-transport=http : FrankenPHP inner proxy transport (http|https)}
        {--json : Output JSON}
        {--stream-json : Stream newline-delimited JSON progress frames}';

    #[\Override]
    protected $description = 'Create a new app on an app node.';

    public function handle(): int
    {
        $node = $this->resolveNode();

        if (is_int($node)) {
            return $node;
        }

        $name = $this->resolveName();

        if ($name === null) {
            return $this->failValidation('name', 'App name is required.');
        }

        $nameValidation = $this->validateName($name);

        if ($nameValidation !== null) {
            return $this->failValidation('name', $nameValidation);
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
                'runtime_proxy_transport' => $this->stringOption('runtime-proxy-transport'),
            ],
            fn (ProgressEventType $type, array $payload): int => $this->renderProgressTerminalFrame($type, $payload),
        );
    }

    private function resolveNode(): string|int
    {
        $node = $this->stringOption('node');

        if ($node !== null) {
            return $node;
        }

        if ($this->allowsInteractiveInput()) {
            try {
                $defaultNode = app(OrbitConfigStore::class)->defaultNode();
            } catch (OrbitConfigStoreException $exception) {
                return $this->renderFailure($exception->orbitCode, $exception->getMessage());
            }

            return $this->promptForAppNewTargetNode($defaultNode);
        }

        try {
            $node = app(OrbitConfigStore::class)->defaultNode();
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        }

        if ($node === null) {
            return $this->failValidation('node', 'The --node option is required.');
        }

        return $node;
    }

    private function resolveName(): ?string
    {
        $name = $this->stringArgument('name');

        if ($name !== null) {
            return $name;
        }

        if ($this->allowsInteractiveInput()) {
            return trim(text(
                label: 'App name (slug):',
                required: true,
                validate: fn (string $value): ?string => $this->validateName(trim($value)),
            ));
        }

        return null;
    }

    private function validateName(string $name): ?string
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name)) {
            return 'App name must match ^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$ (lowercase letters, digits, hyphens; no leading or trailing hyphen).';
        }

        if (strlen($name) > 40) {
            return 'App name must not exceed 40 characters.';
        }

        return null;
    }
}
