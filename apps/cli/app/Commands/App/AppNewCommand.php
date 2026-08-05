<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\Concerns\PromptsForGatewayRegistryEntities;
use App\Exceptions\OrbitConfigStoreException;
use App\Services\Apps\AppNameInputValidator;
use App\Services\Apps\AppNewSourceInputResolver;
use App\Services\Apps\AppNewSourceValidationFailed;
use App\Services\OrbitConfigStore;
use Orbit\Core\Progress\ProgressEventType;

use function Laravel\Prompts\text;

final class AppNewCommand extends AppGatewayCommand
{
    use PromptsForGatewayRegistryEntities;

    #[\Override]
    protected $signature = 'app:new
        {name? : App name}
        {--node= : Target instance node}
        {--repo= : Repository to clone}
        {--template-repo= : GitHub template repository (owner/repo)}
        {--new-repo= : New private GitHub repository (owner/repo)}
        {--root=public : Document root relative to instance path}
        {--php-version=8.5 : PHP version}
        {--domain= : Production domain}
        {--runtime-proxy-transport=http : FrankenPHP inner proxy transport (http|https)}
        {--json : Output JSON}
        {--stream-json : Stream newline-delimited JSON progress frames}';

    #[\Override]
    protected $description = 'Create a new app and its first instance on a serving node.';

    public function handle(): int
    {
        $outputModeValidation = $this->validateProgressOutputMode();

        if ($outputModeValidation !== null) {
            return $outputModeValidation;
        }

        $node = $this->resolveNode();

        if (is_int($node)) {
            return $node;
        }

        $name = $this->resolveName();

        if ($name === null) {
            return $this->failValidation('name', 'App name is required.');
        }

        $nameValidation = app(AppNameInputValidator::class)->validate($name);

        if ($nameValidation !== null) {
            return $this->failValidation('name', $nameValidation);
        }

        try {
            $sources = app(AppNewSourceInputResolver::class);
            $repository = $this->stringOption('repo');
            $templateRepository = $this->stringOption('template-repo');
            $newRepository = $this->stringOption('new-repo');
            $source = $this->allowsInteractiveInput()
                ? $sources->resolveInteractive($repository, $templateRepository, $newRepository)
                : $sources->resolveNonInteractive($repository, $templateRepository, $newRepository);
        } catch (AppNewSourceValidationFailed $exception) {
            return $this->failValidation($exception->field, $exception->getMessage(), $exception->meta);
        }

        return $this->streamProgress(
            '/api/apps',
            [
                'name' => $name,
                'node' => $node,
                ...$source,
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
            $names = app(AppNameInputValidator::class);

            return trim(text(
                label: 'App name (slug):',
                required: true,
                validate: static fn (string $value): ?string => $names->validate(trim($value)),
            ));
        }

        return null;
    }
}
