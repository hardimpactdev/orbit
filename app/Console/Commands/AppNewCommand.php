<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Apps\CreateAppSourceOnNode;
use App\Actions\Apps\EnactAppRuntime;
use App\Concerns\PromptsForRegistryEntities;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Apps\CreateAppRequest;
use App\Http\Gateway\Responses\Apps\AppCreateResponse;
use App\Models\App;
use App\Models\LocalNodeDefault;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Support\GitRepositoryReference;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

#[Signature('app:new
    {name? : App name}
    {--node= : Target app node}
    {--repo= : Repository to clone}
    {--root=public : Document root relative to app path}
    {--php-version=8.5 : PHP version}
    {--domain= : Production domain}
    {--json : Output JSON}')]
#[Description('Create a new app on an app node')]
class AppNewCommand extends Command
{
    use PromptsForRegistryEntities;
    use WithSpinner;
    use WithStepTree;

    private const array SUPPORTED_PHP_VERSIONS = ['8.5'];

    /**
     * @var array<string, string>|null
     */
    private ?array $appNameNodeIndex = null;

    public function handle(CreateAppSourceOnNode $createAppSourceOnNode, EnactAppRuntime $enactAppRuntime): int
    {
        $callerRole = (bool) config('orbit.is_gateway', false) ? 'gateway' : 'control';

        $input = $this->resolveInput();

        if (is_int($input)) {
            return $input;
        }

        if ($callerRole === 'control') {
            return $this->forwardCreate($input);
        }

        $node = $this->resolveTargetNode($input['node']);

        if (is_int($node)) {
            return $node;
        }

        $this->appNameNodeIndex = null;
        $existingNode = $this->existingAppNodeForName($input['name']);

        if ($existingNode instanceof GatewayApiException) {
            return $this->failCommand(
                code: $existingNode->errorCode() ?? 'gateway_unavailable',
                message: $existingNode->getMessage(),
                meta: $existingNode->errorMeta(),
            );
        }

        if ($existingNode !== null) {
            return $this->failAppNameCollision($input['name'], $existingNode);
        }

        $routeDomain = $this->proxyRouteDomain($input, $node);
        $existingRoute = ProxyRoute::query()
            ->where('domain', $routeDomain)
            ->first();

        if ($existingRoute instanceof ProxyRoute) {
            return $this->failCommand(
                code: 'proxy.domain_conflict',
                message: "Proxy route domain '{$routeDomain}' is already registered.",
                meta: [
                    'domain' => $routeDomain,
                    'owner_type' => $existingRoute->owner_type,
                    'kind' => $existingRoute->kind,
                ],
            );
        }

        if (! $this->wantsJson()) {
            return $this->createForHuman($input, $node, $createAppSourceOnNode, $enactAppRuntime);
        }

        $source = $this->createSource($input, $node, $createAppSourceOnNode);

        if (! $source['result']->successful()) {
            return $this->failCommand(
                code: 'app.source_creation_failed',
                message: "Source creation for app '{$input['name']}' failed on node '{$node->name}'.",
                meta: [
                    'reason' => trim((string) $source['result']->output()) ?: 'source creation failed',
                    ...($input['repository'] !== null ? ['transport' => GitRepositoryReference::transport($input['repository'])] : []),
                ],
            );
        }

        $app = $this->createAppRecord($input, $node, $source);
        $warnings = $enactAppRuntime->handle($app);

        return $this->successCommand([
            'result' => ['action' => 'created'],
            'app' => $this->appPayload($app),
        ], $warnings);
    }

    /**
     * @param  array{name: string, node: string, repository: ?string, root: string, php_version: string, domain: ?string}  $input
     */
    private function createForHuman(
        array $input,
        Node $node,
        CreateAppSourceOnNode $createAppSourceOnNode,
        EnactAppRuntime $enactAppRuntime,
    ): int {
        $source = null;
        $app = null;
        $warnings = [];
        $failureMessage = null;

        $exitCode = $this->runStepTree(
            'Creating App',
            [
                [
                    'key' => 'create_source',
                    'label' => 'Create app source',
                    'doneLabel' => 'Created app source',
                    'run' => function () use ($input, $node, $createAppSourceOnNode, &$source, &$failureMessage): string {
                        $source = $this->createSource($input, $node, $createAppSourceOnNode);

                        if (! $source['result']->successful()) {
                            $failureMessage = "Source creation for app '{$input['name']}' failed on node '{$node->name}'.";

                            return 'fail:'.$this->sourceFailureReason($source, $input);
                        }

                        return (string) $source['path'];
                    },
                ],
                [
                    'key' => 'register_app',
                    'label' => 'Apply and verify app registration',
                    'doneLabel' => 'Applied and verified app registration',
                    'run' => function () use ($input, $node, &$source, &$app): string {
                        if (! is_array($source)) {
                            return 'fail:App source was not created.';
                        }

                        $app = $this->createAppRecord($input, $node, $source);

                        return (string) $app->name;
                    },
                ],
                [
                    'key' => 'apply_php_fpm',
                    'label' => 'Apply PHP-FPM configuration',
                    'doneLabel' => 'Applied PHP-FPM configuration',
                    'run' => function () use ($enactAppRuntime, &$app, &$warnings): string {
                        if (! $app instanceof App) {
                            return 'fail:App was not registered.';
                        }

                        $warnings = $enactAppRuntime->handle($app);

                        return 'ready';
                    },
                ],
                [
                    'key' => 'apply_routes',
                    'label' => 'Apply proxy routes',
                    'doneLabel' => 'Applied proxy routes',
                    'run' => fn (): string => 'ready',
                ],
            ],
            doneFooter: "App '{$input['name']}' created",
            failFooter: "Failed to create app '{$input['name']}'.",
        );

        if ($exitCode !== self::SUCCESS) {
            $this->error($failureMessage ?? "Failed to create app '{$input['name']}'.");

            return self::FAILURE;
        }

        if (! $app instanceof App) {
            $this->error("Failed to create app '{$input['name']}'.");

            return self::FAILURE;
        }

        return $this->successCommand([
            'result' => ['action' => 'created'],
            'app' => $this->appPayload($app),
        ], $warnings);
    }

    /**
     * @param  array{name: string, node: string, repository: ?string, root: string, php_version: string, domain: ?string}  $input
     * @return array{path: string, result: mixed}
     */
    private function createSource(array $input, Node $node, CreateAppSourceOnNode $createAppSourceOnNode): array
    {
        return $createAppSourceOnNode->handle(
            node: $node,
            name: $input['name'],
            repository: $input['repository'],
            domain: $input['domain'],
        );
    }

    /**
     * @param  array{name: string, node: string, repository: ?string, root: string, php_version: string, domain: ?string}  $input
     * @param  array{path: string, result: mixed}  $source
     */
    private function createAppRecord(array $input, Node $node, array $source): App
    {
        $app = App::query()->create([
            'name' => $input['name'],
            'node_id' => $node->id,
            'environment' => $input['domain'] !== null ? 'production' : 'development',
            'domain' => $input['domain'],
            'path' => $source['path'],
            'document_root' => $input['root'],
            'repository' => $input['repository'],
            'php_version' => $input['php_version'],
            'adopted' => false,
        ]);

        $app->setRelation('node', $node);

        return $app;
    }

    /**
     * @param  array{name: string, node: string, repository: ?string, root: string, php_version: string, domain: ?string}  $input
     * @param  array{path: string, result: mixed}  $source
     */
    private function sourceFailureReason(array $source, array $input): string
    {
        $reason = trim((string) $source['result']->output()) ?: 'source creation failed';

        if ($input['repository'] === null) {
            return $reason;
        }

        return $reason.' ('.GitRepositoryReference::transport($input['repository']).')';
    }

    /**
     * @param  array{name: string, node: string, repository: ?string, root: string, php_version: string, domain: ?string}  $input
     */
    private function forwardCreate(array $input): int
    {
        try {
            /** @var AppCreateResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new CreateAppRequest(
                    name: $input['name'],
                    node: $input['node'],
                    repository: $input['repository'],
                    root: $input['root'],
                    phpVersion: $input['php_version'],
                    domain: $input['domain'],
                ))
                ->dto();
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to create apps.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to create apps.',
                meta: [],
            );
        }

        return $this->successCommand($dto->data, $dto->warnings);
    }

    /**
     * @param  array{name: string, node: string, repository: ?string, root: string, php_version: string, domain: ?string}  $input
     */
    private function proxyRouteDomain(array $input, Node $node): string
    {
        if ($input['domain'] !== null) {
            return $input['domain'];
        }

        $tld = is_string($node->tld) ? trim($node->tld, '.') : '';

        if ($tld === '') {
            return $input['name'];
        }

        return "{$input['name']}.{$tld}";
    }

    /**
     * @return array{name: string, node: string, repository: ?string, root: string, php_version: string, domain: ?string}|int
     */
    private function resolveInput(): array|int
    {
        $node = $this->resolveNodeInput();
        if (is_int($node)) {
            return $node;
        }

        $name = $this->resolveNameInput();
        if (is_int($name)) {
            return $name;
        }

        $repository = $this->stringOption('repo');
        if ($repository === null && $this->isInteractiveInput() && confirm('Clone from a git repository?', default: false)) {
            $repository = trim(text(label: 'Repository URL (or GitHub owner/repo)', required: true));
        }

        $repository = GitRepositoryReference::canonicalize($repository);
        $root = $this->stringOption('root') ?? 'public';
        $phpVersion = $this->stringOption('php-version') ?? '8.5';
        $domain = $this->stringOption('domain');

        if ($repository === false) {
            return $this->failValidation('repo', 'Repository must be a full Git URL or GitHub owner/repo shorthand.');
        }

        if ($root === '' || preg_match('/[\x00-\x1F;`$|&<>"\'\\\\]/', $root)) {
            return $this->failValidation('root', 'Document root is invalid.');
        }

        if (! in_array($phpVersion, self::SUPPORTED_PHP_VERSIONS, true)) {
            return $this->failValidation('php_version', 'Unsupported PHP version.');
        }

        if ($domain !== null && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return $this->failValidation('domain', 'Production domain is invalid.');
        }

        return [
            'name' => $name,
            'node' => $node,
            'repository' => $repository,
            'root' => $root,
            'php_version' => $phpVersion,
            'domain' => $domain,
        ];
    }

    private function resolveNameInput(): string|int
    {
        $name = $this->stringArgument('name');

        if ($name === null) {
            if (! $this->isInteractiveInput()) {
                return $this->failValidation('name', 'App name is required.');
            }

            $appNameNodeIndex = $this->appNameNodeIndex();

            if ($appNameNodeIndex instanceof GatewayApiException) {
                return $this->failCommand(
                    code: $appNameNodeIndex->errorCode() ?? 'gateway_unavailable',
                    message: $appNameNodeIndex->getMessage(),
                    meta: $appNameNodeIndex->errorMeta(),
                );
            }

            return trim(text(
                label: 'App name (slug)',
                required: true,
                validate: fn (string $value): ?string => $this->appNameValidationMessage(trim($value), $appNameNodeIndex),
            ));
        }

        $shapeError = $this->appNameShapeValidationMessage($name);

        if ($shapeError !== null) {
            return $this->failValidation('name', $shapeError);
        }

        if (! (bool) config('orbit.is_gateway', false)) {
            return $name;
        }

        $existingNode = $this->existingAppNodeForName($name);

        if ($existingNode instanceof GatewayApiException) {
            return $this->failCommand(
                code: $existingNode->errorCode() ?? 'gateway_unavailable',
                message: $existingNode->getMessage(),
                meta: $existingNode->errorMeta(),
            );
        }

        if ($existingNode !== null) {
            return $this->failAppNameCollision($name, $existingNode);
        }

        return $name;
    }

    private function resolveNodeInput(): string|int
    {
        $node = $this->stringOption('node');

        if ($node !== null) {
            return $node;
        }

        if (! $this->isInteractiveInput()) {
            $default = $this->validLocalDefaultAppNodeName();

            if ($default instanceof GatewayApiException) {
                return $this->failCommand(
                    code: $default->errorCode() ?? 'gateway_unavailable',
                    message: $default->getMessage(),
                    meta: $default->errorMeta(),
                );
            }

            if ($default !== null) {
                return $default;
            }

            return $this->failValidation('node', 'The --node option is required in non-interactive mode.');
        }

        try {
            $node = $this->promptForVisibleNode(
                label: 'Select target app node',
                role: 'app',
                preferred: $this->localDefaultNodeName(),
            );
        } catch (PromptAborted) {
            return $this->failValidation('node', 'Operation cancelled.');
        }

        if ($node instanceof GatewayApiException) {
            return $this->failCommand(
                code: $node->errorCode() ?? 'gateway_unavailable',
                message: $node->getMessage(),
                meta: $node->errorMeta(),
            );
        }

        return $node;
    }

    /**
     * @param  array<string, string>  $appNameNodeIndex
     */
    private function appNameValidationMessage(string $name, array $appNameNodeIndex): ?string
    {
        $shapeError = $this->appNameShapeValidationMessage($name);

        if ($shapeError !== null) {
            return $shapeError;
        }

        if (array_key_exists($name, $appNameNodeIndex)) {
            return $this->appNameCollisionMessage($name, $appNameNodeIndex[$name]);
        }

        return null;
    }

    private function appNameShapeValidationMessage(string $name): ?string
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name) || mb_strlen($name) > 40) {
            return 'App name must be a slug of 40 characters or fewer.';
        }

        return null;
    }

    private function existingAppNodeForName(string $name): string|GatewayApiException|null
    {
        $appNameNodeIndex = $this->appNameNodeIndex();

        if ($appNameNodeIndex instanceof GatewayApiException) {
            return $appNameNodeIndex;
        }

        if (! array_key_exists($name, $appNameNodeIndex)) {
            return null;
        }

        return $appNameNodeIndex[$name];
    }

    /**
     * @return array<string, string>|GatewayApiException
     */
    private function appNameNodeIndex(): array|GatewayApiException
    {
        if ($this->appNameNodeIndex !== null) {
            return $this->appNameNodeIndex;
        }

        if ((bool) config('orbit.is_gateway', false)) {
            $this->appNameNodeIndex = App::query()
                ->with('node')
                ->get()
                ->mapWithKeys(fn (App $app): array => [
                    $app->name => $app->node->name,
                ])
                ->all();

            return $this->appNameNodeIndex;
        }

        $apps = $this->visibleAppPromptPayloads();

        if ($apps instanceof GatewayApiException) {
            return $apps;
        }

        $index = [];

        foreach ($apps as $app) {
            $name = is_string($app['name'] ?? null) ? $app['name'] : '';

            if ($name === '') {
                continue;
            }

            $index[$name] = is_string($app['node'] ?? null) ? $app['node'] : '';
        }

        $this->appNameNodeIndex = $index;

        return $this->appNameNodeIndex;
    }

    private function validLocalDefaultAppNodeName(): string|GatewayApiException|null
    {
        $name = $this->localDefaultNodeName();

        if ($name === null) {
            return null;
        }

        if ((bool) config('orbit.is_gateway', false)) {
            $node = Node::query()
                ->where('name', $name)
                ->where('role', 'app')
                ->where('environment', 'development')
                ->where('status', 'active')
                ->first();

            return $node instanceof Node ? $node->name : null;
        }

        $nodes = $this->visibleNodePromptPayloads(role: 'app', environment: 'development', activeOnly: true);

        if ($nodes instanceof GatewayApiException) {
            return $nodes;
        }

        foreach ($nodes as $node) {
            if (($node['name'] ?? null) === $name) {
                return $name;
            }
        }

        return null;
    }

    private function localDefaultNodeName(): ?string
    {
        $name = LocalNodeDefault::query()->value('default_node_name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function failAppNameCollision(string $name, string $node): int
    {
        return $this->failCommand(
            code: 'app.collision',
            message: $this->appNameCollisionMessage($name, $node),
            meta: [
                'name' => $name,
                'node' => $node,
            ],
        );
    }

    private function appNameCollisionMessage(string $name, string $node): string
    {
        return "App name '{$name}' is already registered in the gateway app registry on node '{$node}'.";
    }

    private function resolveTargetNode(string $nodeName): Node|int
    {
        $node = Node::query()->where('name', $nodeName)->first();

        if (! $node instanceof Node) {
            return $this->failCommand(
                code: 'validation_failed',
                message: "Node '{$nodeName}' was not found.",
                meta: ['field' => 'node'],
            );
        }

        if ($node->role !== 'app' || $node->status !== 'active') {
            return $this->failCommand(
                code: 'app.ineligible_node',
                message: "Node '{$node->name}' is not an active app node.",
                meta: [
                    'node' => $node->name,
                    'role' => $node->role,
                    'status' => $node->status,
                ],
            );
        }

        return $node;
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(App $app): array
    {
        return [
            'name' => $app->name,
            'node' => $app->node?->name,
            'environment' => $app->environment,
            'url' => $app->url(),
            'path' => $app->path,
            'root' => $app->document_root,
            'repository' => $app->repository,
            'php_version' => $app->php_version,
            'adopted' => $app->adopted,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $warnings
     */
    private function successCommand(array $data, array $warnings = []): int
    {
        if (! $this->wantsJson()) {
            /** @var array{name?: string, node?: string, environment?: string, url?: string} $app */
            $app = is_array($data['app'] ?? null) ? $data['app'] : [];

            $this->line(sprintf(
                "App '%s' created successfully on node '%s'.",
                (string) ($app['name'] ?? ''),
                (string) ($app['node'] ?? ''),
            ));
            $this->line('Environment: '.(string) ($app['environment'] ?? ''));
            $this->line('URL: '.(string) ($app['url'] ?? ''));

            if ($warnings !== []) {
                $this->line('Warnings:');

                foreach ($warnings as $warning) {
                    $this->line('- '.(string) ($warning['message'] ?? $warning['code'] ?? 'Warning'));

                    if (isset($warning['next_command']) && is_string($warning['next_command'])) {
                        $this->line('  Retry with: orbit '.$warning['next_command']);
                    }
                }
            }

            return self::SUCCESS;
        }

        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => ['warnings' => $warnings],
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if (! $this->wantsJson()) {
            $this->error($message);

            return self::FAILURE;
        }

        $this->line(json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }

    private function failValidation(string $field, string $message): int
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: $message,
            meta: ['field' => $field],
        );
    }
}
