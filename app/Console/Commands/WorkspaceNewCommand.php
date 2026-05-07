<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Workspaces\CreateWorkspace;
use App\Exceptions\WorkspaceCreateFailed;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\CreateWorkspaceRequest;
use App\Http\Gateway\Responses\Workspaces\CreateWorkspaceResponse;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('workspace:new
    {name? : Workspace name}
    {--app= : Parent app name}
    {--base=main : Base git ref}
    {--php-version= : PHP version override}
    {--json : Output as JSON}')]
#[Description('Create a new workspace intent')]
class WorkspaceNewCommand extends Command
{
    public function handle(CreateWorkspace $createWorkspace): int
    {
        $callerRole = $this->callerRole();

        if ($callerRole === 'app') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control or gateway node.',
                meta: ['caller_role' => 'app'],
            );
        }

        if ($callerRole === 'unknown') {
            return $this->failCommand(
                code: 'local_context_invalid',
                message: 'Local node role setting is invalid.',
                meta: [
                    'setting' => 'general.local_node_role',
                    'reason' => 'unsupported_value',
                    'caller_role' => 'unknown',
                ],
            );
        }

        $name = $this->resolveName();

        if ($name === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Workspace name is required.',
                meta: ['field' => 'name'],
            );
        }

        if ($name === 'main') {
            return $this->failCommand(
                code: 'validation_failed',
                message: "The workspace name 'main' is reserved.",
                meta: ['field' => 'name'],
            );
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name)) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Workspace name must match ^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$ (lowercase letters, digits, hyphens; no leading or trailing hyphen).',
                meta: ['field' => 'name'],
            );
        }

        if (strlen($name) > 63) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Workspace name must not exceed 63 characters.',
                meta: ['field' => 'name'],
            );
        }

        $app = $this->resolveApp($callerRole);

        if ($app === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Parent app is required. Pass --app= or run from an app directory.',
                meta: ['field' => 'app'],
            );
        }

        $base = $this->stringOption('base') ?? 'main';
        $phpVersion = $this->stringOption('php-version');

        if ($callerRole === 'control') {
            return $this->forwardCreate($name, $app, $base, $phpVersion);
        }

        return $this->createLocally($createWorkspace, $name, $app, $base, $phpVersion);
    }

    private function createLocally(CreateWorkspace $createWorkspace, string $name, string $app, string $base, ?string $phpVersion): int
    {
        $appModel = App::query()
            ->with('node')
            ->where('name', $app)
            ->first();

        if (! $appModel instanceof App) {
            return $this->failCommand(
                code: 'app.not_found',
                message: "App '{$app}' not found.",
                meta: ['app' => $app],
            );
        }

        if (! $this->supportedPhpVersion($phpVersion)) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Unsupported PHP version.',
                meta: ['field' => 'php_version', 'reason' => 'unsupported_php_version'],
            );
        }

        $existing = Workspace::query()
            ->where('app_id', $appModel->id)
            ->where('name', $name)
            ->first();

        if ($existing instanceof Workspace) {
            return $this->failCommand(
                code: 'workspace.already_exists',
                message: "Workspace '{$name}' already exists for app '{$app}'.",
                meta: ['name' => $name, 'app' => $app],
            );
        }

        try {
            $result = $createWorkspace->handle($appModel, $name, $base, $phpVersion);
        } catch (WorkspaceCreateFailed $exception) {
            return $this->failCommand(
                code: $exception->errorCode,
                message: $exception->getMessage(),
                meta: $exception->meta,
            );
        }

        return $this->respondSuccess($result);
    }

    private function forwardCreate(string $name, string $app, string $base, ?string $phpVersion): int
    {
        try {
            /** @var CreateWorkspaceResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new CreateWorkspaceRequest($name, $app, $base, $phpVersion))
                ->dto();
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to create a workspace.',
                meta: $e->errorMeta(),
                data: $e->errorData(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to create a workspace.',
                meta: [],
            );
        }

        return $this->respondSuccess([
            'result' => ['action' => $dto->action],
            'workspace' => [
                'name' => $dto->name,
                'app' => $dto->app,
                'node' => $dto->node,
                'path' => $dto->path,
                'url' => $dto->url,
                'php_version' => $dto->phpVersion,
                'php_inherited' => $dto->phpInherited,
                'adopted' => $dto->adopted,
                'lifecycle_status' => $dto->lifecycleStatus,
            ],
            'meta' => [
                'node' => $dto->node,
                'base' => $dto->base,
                'http_probe' => $dto->httpProbe,
                'warnings' => $dto->warnings,
            ],
        ]);
    }

    private function resolveName(): ?string
    {
        $name = $this->stringArgument('name');

        if ($name !== null) {
            return $name;
        }

        if ($this->isInteractiveInput()) {
            return trim(text(
                label: 'Workspace name',
                required: true,
                validate: fn (string $value): ?string => $this->validatePromptName($value),
            ));
        }

        return null;
    }

    private function validatePromptName(string $value): ?string
    {
        $name = trim($value);

        if ($name === '') {
            return 'Workspace name is required.';
        }

        if ($name === 'main') {
            return "The workspace name 'main' is reserved.";
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name)) {
            return 'Workspace name must match ^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$';
        }

        if (strlen($name) > 63) {
            return 'Workspace name must not exceed 63 characters.';
        }

        return null;
    }

    private function resolveApp(string $callerRole): ?string
    {
        $app = $this->stringOption('app');

        if ($app !== null) {
            return $app;
        }

        if ($callerRole !== 'gateway') {
            return null;
        }

        if (! $this->isInteractiveInput()) {
            return null;
        }

        $apps = App::query()
            ->with('node')
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        if ($apps->isEmpty()) {
            return null;
        }

        return select(
            label: 'Which app owns this workspace?',
            options: $apps->mapWithKeys(fn (App $app): array => [
                $app->name => "{$app->name} (".($app->node->name ?? 'unknown').')',
            ])->all(),
            required: true,
        );
    }

    private function callerRole(): string
    {
        $localRole = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('role');

        if (! is_string($localRole) || $localRole === '') {
            return 'control';
        }

        if (! in_array($localRole, ['gateway', 'app', 'control'], true)) {
            return 'unknown';
        }

        return $localRole;
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    private function supportedPhpVersion(?string $phpVersion): bool
    {
        return $phpVersion === null || in_array($phpVersion, CreateWorkspace::SUPPORTED_PHP_VERSIONS, true);
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function respondSuccess(array $data): int
    {
        $workspace = $data['workspace'];
        $meta = $data['meta'];

        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => [
                        'result' => $data['result'],
                        'workspace' => $workspace,
                    ],
                    'meta' => $meta,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line("Workspace '{$workspace['name']}' created for app '{$workspace['app']}'.");
        $this->line("  Path: {$workspace['path']}");
        $this->line("  URL: {$workspace['url']}");
        $this->line("  Status: {$workspace['lifecycle_status']}");

        if ($workspace['php_version'] !== null) {
            $this->line("  PHP: {$workspace['php_version']}");
        }

        if (($meta['warnings'] ?? []) !== []) {
            $this->line('  Warnings:');

            foreach ($meta['warnings'] as $warning) {
                $message = $warning['message'] ?? $warning['code'] ?? 'unknown warning';
                $this->line("    - {$message}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    private function failCommand(string $code, string $message, array $meta = [], array $data = []): int
    {
        if ($this->wantsJson()) {
            $error = [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ];

            if ($data !== []) {
                $error['data'] = $data;
            }

            $this->line(json_encode([
                'error' => $error,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }
}
