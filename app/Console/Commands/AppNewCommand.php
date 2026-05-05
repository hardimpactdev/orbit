<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Apps\CreateAppSourceOnNode;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Apps\CreateAppRequest;
use App\Http\Gateway\Responses\Apps\AppCreateResponse;
use App\Models\App;
use App\Models\Node;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

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
    private const array SUPPORTED_PHP_VERSIONS = ['8.5'];

    public function handle(CreateAppSourceOnNode $createAppSourceOnNode): int
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

        $existingApp = App::query()
            ->with('node')
            ->where('name', $input['name'])
            ->first();

        if ($existingApp instanceof App) {
            return $this->failCommand(
                code: 'app.collision',
                message: "App name '{$input['name']}' is already registered in the gateway app registry on node '{$existingApp->node?->name}'.",
                meta: [
                    'name' => $input['name'],
                    'node' => $existingApp->node?->name,
                ],
            );
        }

        $source = $createAppSourceOnNode->handle(
            node: $node,
            name: $input['name'],
            repository: $input['repository'],
            domain: $input['domain'],
        );

        if (! $source['result']->successful()) {
            return $this->failCommand(
                code: 'app.source_creation_failed',
                message: "Source creation for app '{$input['name']}' failed on node '{$node->name}'.",
                meta: [
                    'reason' => trim($source['result']->output()) ?: 'source creation failed',
                    ...($input['repository'] !== null ? ['transport' => $this->repositoryTransport($input['repository'])] : []),
                ],
            );
        }

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

        return $this->successCommand([
            'result' => ['action' => 'created'],
            'app' => $this->appPayload($app),
        ]);
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

    /**
     * @return array{name: string, node: string, repository: ?string, root: string, php_version: string, domain: ?string}|int
     */
    private function resolveInput(): array|int
    {
        $name = $this->stringArgument('name');
        if ($name === null && $this->isInteractiveInput()) {
            $name = trim((string) $this->ask('App name (slug)'));
        }

        if ($name === null) {
            return $this->failValidation('name', 'App name is required.');
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name) || mb_strlen($name) > 40) {
            return $this->failValidation('name', 'App name must be a slug of 40 characters or fewer.');
        }

        $node = $this->resolveNodeInput();
        if (is_int($node)) {
            return $node;
        }

        $repository = $this->stringOption('repo');
        if ($repository === null && $this->isInteractiveInput() && $this->confirm('Clone from a git repository?', false)) {
            $repository = trim((string) $this->ask('Repository URL (or GitHub owner/repo)'));
        }

        $repository = $this->canonicalRepository($repository);
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

    private function resolveNodeInput(): string|int
    {
        $node = $this->stringOption('node');

        if ($node !== null) {
            return $node;
        }

        if (! $this->isInteractiveInput()) {
            return $this->failValidation('node', 'The --node option is required in non-interactive mode.');
        }

        $nodes = Node::query()
            ->where('role', 'app')
            ->where('status', 'active')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if ($nodes === []) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'No active app nodes are registered.',
                meta: ['field' => 'node'],
            );
        }

        return (string) $this->choice('Select target app node', $nodes);
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

    private function canonicalRepository(?string $repository): string|false|null
    {
        if ($repository === null) {
            return null;
        }

        if (preg_match('/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/', $repository)) {
            return "git@github.com:{$repository}.git";
        }

        if (preg_match('/^(git@|https:\/\/|ssh:\/\/).+/', $repository)) {
            return $repository;
        }

        return false;
    }

    private function repositoryTransport(string $repository): string
    {
        return str_starts_with($repository, 'https://') ? 'https' : 'ssh';
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

            $this->line('┌ Creating App');
            $this->line('○ Create app source');
            $this->line('○ Apply and verify app registration');
            $this->line('○ Apply PHP-FPM configuration');
            $this->line('○ Apply proxy routes');
            $this->line("└ App '".(string) ($app['name'] ?? '')."' created");
            $this->line(sprintf(
                "App '%s' created successfully on node '%s'.",
                (string) ($app['name'] ?? ''),
                (string) ($app['node'] ?? ''),
            ));
            $this->line('Environment: '.(string) ($app['environment'] ?? ''));
            $this->line('URL: '.(string) ($app['url'] ?? ''));

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
