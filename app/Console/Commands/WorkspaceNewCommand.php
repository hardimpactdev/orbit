<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Workspaces\CreateWorkspace;
use App\Actions\Workspaces\CreateWorkspaceProgress;
use App\Concerns\PromptsForRegistryEntities;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Exceptions\PromptAborted;
use App\Exceptions\WorkspaceCreateFailed;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\CreateWorkspaceRequest;
use App\Http\Gateway\Responses\Workspaces\CreateWorkspaceResponse;
use App\Http\Gateway\WorkspaceNewGatewayStreamClient;
use App\Models\App;
use App\Models\Workspace;
use App\Support\Cli\RemoteProgressRenderer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

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
    use PromptsForRegistryEntities;
    use WithSpinner;
    use WithStepTree;

    public function handle(
        CreateWorkspace $createWorkspace,
        CreateWorkspaceProgress $createProgress,
        WorkspaceNewGatewayStreamClient $createStream,
    ): int {
        $onGateway = (bool) config('orbit.is_gateway', false);

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

        $app = $this->resolveApp($onGateway);

        if ($app === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Parent app is required. Pass --app= or run from an app directory.',
                meta: ['field' => 'app'],
            );
        }

        $base = $this->stringOption('base') ?? 'main';
        $phpVersion = $this->stringOption('php-version');

        if (! $onGateway) {
            return $this->forwardCreate($createStream, $name, $app, $base, $phpVersion);
        }

        return $this->createLocally($createWorkspace, $createProgress, $name, $app, $base, $phpVersion);
    }

    private function createLocally(
        CreateWorkspace $createWorkspace,
        CreateWorkspaceProgress $createProgress,
        string $name,
        string $app,
        string $base,
        ?string $phpVersion,
    ): int {
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

        try {
            $createWorkspace->ensureSupportedPhpVersion($phpVersion);
        } catch (WorkspaceCreateFailed $exception) {
            return $this->failCommand(
                code: $exception->errorCode,
                message: $exception->getMessage(),
                meta: $exception->meta,
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

        if (! $this->wantsJson()) {
            return $this->createLocallyForHuman($createWorkspace, $createProgress, $appModel, $name, $base, $phpVersion);
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

    private function createLocallyForHuman(
        CreateWorkspace $createWorkspace,
        CreateWorkspaceProgress $createProgress,
        App $app,
        string $name,
        string $base,
        ?string $phpVersion,
    ): int {
        try {
            $node = $createWorkspace->resolveAppNode($app);
        } catch (WorkspaceCreateFailed $exception) {
            return $this->failCommand(
                code: $exception->errorCode,
                message: $exception->getMessage(),
                meta: $exception->meta,
            );
        }

        $plan = $createProgress->for($app, $node, $name, $base, $phpVersion);
        $exitCode = $this->runStepTree(
            $plan->title(),
            $plan->steps(),
            doneFooter: $plan->doneFooter(),
            failFooter: $plan->failFooter(),
        );

        if ($exitCode !== self::SUCCESS) {
            $failure = $plan->failure();

            if ($failure !== null) {
                return $this->failCommand(
                    code: $failure['code'],
                    message: $failure['message'],
                    meta: $failure['meta'],
                );
            }

            return self::FAILURE;
        }

        $result = $plan->result();

        if (($result['workspace'] ?? []) === []) {
            return $this->failCommand(
                code: 'workspace.enactment_failed',
                message: "Workspace '{$name}' was not created.",
                meta: ['step' => 'create_intent'],
            );
        }

        return $this->respondSuccess($result);
    }

    private function forwardCreate(
        WorkspaceNewGatewayStreamClient $createStream,
        string $name,
        string $app,
        string $base,
        ?string $phpVersion,
    ): int {
        if (! $this->wantsJson()) {
            return $this->forwardCreateForHuman($createStream, $name, $app, $base, $phpVersion);
        }

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
                'agent_ide' => $dto->agentIde,
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

    private function forwardCreateForHuman(
        WorkspaceNewGatewayStreamClient $createStream,
        string $name,
        string $app,
        string $base,
        ?string $phpVersion,
    ): int {
        $renderer = new RemoteProgressRenderer($this->output);
        $completeData = [];
        $errorData = [];
        $footer = 'Done';

        $result = $createStream->run($name, $app, $base, $phpVersion, function (string $event, array $payload) use ($renderer, &$completeData, &$errorData, &$footer): void {
            if ($event === 'tree') {
                $title = is_string($payload['title'] ?? null) ? $payload['title'] : '';
                $steps = is_array($payload['steps'] ?? null) ? $payload['steps'] : [];
                $renderer->tree($title, $steps);

                return;
            }

            if ($event === 'step') {
                $renderer->step(
                    is_string($payload['key'] ?? null) ? $payload['key'] : '',
                    is_string($payload['status'] ?? null) ? $payload['status'] : '',
                    is_string($payload['message'] ?? null) ? $payload['message'] : null,
                );

                return;
            }

            if ($event === 'complete') {
                $completeData = is_array($payload['data'] ?? null) ? $payload['data'] : [];

                if (is_string($completeData['footer'] ?? null)) {
                    $footer = $completeData['footer'];
                }

                return;
            }

            if ($event === 'error') {
                $errorData = is_array($payload['data'] ?? null) ? $payload['data'] : [];

                if (is_string($errorData['footer'] ?? null)) {
                    $footer = $errorData['footer'];
                }
            }
        });

        if ($result instanceof GatewayApiException) {
            return $this->failCommand(
                code: $result->errorCode() ?? 'gateway_unavailable',
                message: $result->getMessage() !== ''
                    ? $result->getMessage()
                    : 'Gateway connection is required to create a workspace.',
                meta: $result->errorMeta(),
            );
        }

        $renderer->finish($footer, $result === self::SUCCESS);

        if ($result !== self::SUCCESS) {
            return $this->failCommand(
                code: is_string($errorData['code'] ?? null) ? $errorData['code'] : 'workspace.enactment_failed',
                message: is_string($errorData['message'] ?? null) ? $errorData['message'] : 'Workspace creation failed.',
                meta: is_array($errorData['meta'] ?? null) ? $errorData['meta'] : [],
            );
        }

        $resultData = is_array($completeData['result'] ?? null) ? $completeData['result'] : [];

        if ($resultData === []) {
            return $this->failCommand(
                code: 'gateway_stream_invalid',
                message: 'Gateway stream completed without workspace creation result data.',
                meta: [],
            );
        }

        return $this->respondSuccess($resultData);
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

    private function resolveApp(bool $onGateway): ?string
    {
        $app = $this->stringOption('app');

        if ($app !== null) {
            return $app;
        }

        if (! $this->isInteractiveInput()) {
            return null;
        }

        try {
            $selected = $this->promptForVisibleApp(label: 'Select parent app');
        } catch (PromptAborted) {
            return null;
        }

        return $selected instanceof GatewayApiException ? null : $selected;
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
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

        $this->line("Workspace '{$workspace['name']}' created on app '{$workspace['app']}' (node '{$workspace['node']}').");
        $this->line("URL: {$workspace['url']}");

        if ($workspace['php_version'] !== null) {
            $suffix = ($workspace['php_inherited'] ?? false) === true ? ' (inherited from app)' : '';
            $this->line("PHP: {$workspace['php_version']}{$suffix}");
        }

        if (($meta['warnings'] ?? []) !== []) {
            $this->line('Warnings:');

            foreach ($meta['warnings'] as $warning) {
                $message = $warning['message'] ?? $warning['code'] ?? 'unknown warning';
                $this->line("- {$message}");

                if (isset($warning['next_command']) && is_string($warning['next_command'])) {
                    $this->line('  Retry with: orbit '.$warning['next_command']);
                }
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
