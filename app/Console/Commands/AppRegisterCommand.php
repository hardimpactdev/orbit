<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Apps\EnactAppRuntime;
use App\Contracts\RemoteShell;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:register
    {name? : App name}
    {--node= : Target app node}
    {--path= : Existing app path on the target node}
    {--root=public : Document root relative to app path}
    {--php-version=8.5 : PHP version}
    {--domain= : Production domain}
    {--json : Output JSON}')]
#[Description('Register or re-apply Orbit management for an app')]
class AppRegisterCommand extends Command
{
    private const array SUPPORTED_PHP_VERSIONS = ['8.5'];

    public function handle(EnactAppRuntime $enactAppRuntime): int
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
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to register apps.',
                meta: [],
            );
        }

        $existingApp = App::query()
            ->with('node')
            ->where('name', $input['name'])
            ->first();

        $node = $this->resolveTargetNode($input['node'], $existingApp);

        if (is_int($node)) {
            return $node;
        }

        $path = $input['path'] ?? $existingApp?->path;

        if (! is_string($path) || $path === '') {
            return $this->failValidation('path', 'The --path option is required when registering an unmanaged app.');
        }

        $pathProbe = app(RemoteShell::class)->run($node, sprintf('test -d %s', escapeshellarg($path)));

        if (! $pathProbe->successful()) {
            return $this->failValidation('path', "Path '{$path}' does not exist on node '{$node->name}'.");
        }

        $pathOwner = App::query()
            ->where('node_id', $node->id)
            ->where('path', $path)
            ->where('name', '!=', $input['name'])
            ->first();

        if ($pathOwner instanceof App) {
            return $this->failCommand(
                code: 'app.path_collision',
                message: "Path '{$path}' on node '{$node->name}' is already owned by app '{$pathOwner->name}'.",
                meta: [
                    'path' => $path,
                    'existing_app' => $pathOwner->name,
                    'node' => $node->name,
                ],
            );
        }

        if ($existingApp instanceof App && $existingApp->node_id !== $node->id) {
            return $this->failCommand(
                code: 'app.path_collision',
                message: "App '{$input['name']}' is already registered on node '{$existingApp->node?->name}'.",
                meta: [
                    'path' => $existingApp->path,
                    'existing_app' => $existingApp->name,
                    'node' => $existingApp->node?->name,
                ],
            );
        }

        if ($existingApp instanceof App && $existingApp->path !== $path) {
            return $this->failCommand(
                code: 'app.path_collision',
                message: "App '{$input['name']}' is already registered at '{$existingApp->path}'.",
                meta: [
                    'path' => $path,
                    'existing_app' => $existingApp->name,
                    'node' => $node->name,
                ],
            );
        }

        $routeConflict = $this->routeConflict($input, $node, $existingApp);

        if ($routeConflict instanceof ProxyRoute) {
            return $this->failCommand(
                code: 'proxy.domain_conflict',
                message: "Proxy route domain '{$routeConflict->domain}' is already registered.",
                meta: [
                    'domain' => $routeConflict->domain,
                    'owner_type' => $routeConflict->owner_type,
                    'kind' => $routeConflict->kind,
                ],
            );
        }

        $action = $existingApp instanceof App ? 'converged' : 'adopted';
        $app = App::query()->updateOrCreate(
            ['name' => $input['name']],
            [
                'node_id' => $node->id,
                'environment' => $input['domain'] !== null ? 'production' : 'development',
                'domain' => $input['domain'] ?? $existingApp?->domain,
                'path' => $path,
                'document_root' => $input['root'],
                'repository' => $existingApp?->repository,
                'php_version' => $input['php_version'],
                'adopted' => $existingApp instanceof App ? $existingApp->adopted : true,
            ],
        );

        $app->setRelation('node', $node);
        $warnings = $enactAppRuntime->handle($app);

        return $this->successCommand([
            'result' => ['action' => $action],
            'app' => $this->appPayload($app),
        ], $warnings, $node);
    }

    /**
     * @return array{name: string, node: ?string, path: ?string, root: string, php_version: string, domain: ?string}|int
     */
    private function resolveInput(): array|int
    {
        $name = $this->stringArgument('name');

        if ($name === null) {
            return $this->failValidation('name', 'App name is required.');
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name) || mb_strlen($name) > 40) {
            return $this->failValidation('name', 'App name must be a slug of 40 characters or fewer.');
        }

        $path = $this->stringOption('path');

        if ($path !== null && ! str_starts_with($path, '/')) {
            return $this->failValidation('path', 'Path must be absolute.');
        }

        $root = $this->stringOption('root') ?? 'public';
        $phpVersion = $this->stringOption('php-version') ?? '8.5';
        $domain = $this->stringOption('domain');

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
            'node' => $this->stringOption('node'),
            'path' => $path,
            'root' => $root,
            'php_version' => $phpVersion,
            'domain' => $domain,
        ];
    }

    private function resolveTargetNode(?string $nodeName, ?App $existingApp): Node|int
    {
        if ($nodeName === null && $existingApp instanceof App) {
            $existingApp->loadMissing('node');
            $node = $existingApp->node;

            if ($node instanceof Node) {
                return $this->ensureEligibleNode($node);
            }
        }

        if ($nodeName === null) {
            $nodeNames = Node::query()
                ->where('role', 'app')
                ->where('status', 'active')
                ->pluck('name')
                ->all();

            if (count($nodeNames) !== 1) {
                return $this->failValidation('node', 'The --node option is required when the target app node cannot be inferred.');
            }

            $nodeName = (string) $nodeNames[0];
        }

        $node = Node::query()->where('name', $nodeName)->first();

        if (! $node instanceof Node) {
            return $this->failValidation('node', "Node '{$nodeName}' was not found.");
        }

        return $this->ensureEligibleNode($node);
    }

    private function ensureEligibleNode(Node $node): Node|int
    {
        if ($node->role === 'app' && $node->status === 'active') {
            return $node;
        }

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

    /**
     * @param  array{name: string, node: ?string, path: ?string, root: string, php_version: string, domain: ?string}  $input
     */
    private function routeConflict(array $input, Node $node, ?App $existingApp): ?ProxyRoute
    {
        $domain = $input['domain'] ?? ($existingApp instanceof App ? $existingApp->domain : null) ?? $this->developmentDomain($input['name'], $node);

        $route = ProxyRoute::query()->where('domain', $domain)->first();

        if (! $route instanceof ProxyRoute) {
            return null;
        }

        if ($existingApp instanceof App && $route->app_id === $existingApp->id) {
            return null;
        }

        return $route;
    }

    private function developmentDomain(string $name, Node $node): string
    {
        $tld = is_string($node->tld) ? trim($node->tld, '.') : '';

        if ($tld === '') {
            return $name;
        }

        return "{$name}.{$tld}";
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
    private function successCommand(array $data, array $warnings, Node $node): int
    {
        if (! $this->wantsJson()) {
            /** @var array{name?: string, url?: string} $app */
            $app = is_array($data['app'] ?? null) ? $data['app'] : [];

            $this->line('┌ Registering App');
            $this->line('○ Resolve app path');
            $this->line('○ Apply and verify app registration');
            $this->line('○ Apply PHP-FPM configuration');
            $this->line('○ Apply proxy routes');
            $this->line("└ App '".(string) ($app['name'] ?? '')."' registered");
            $this->line('URL: '.(string) ($app['url'] ?? ''));

            return self::SUCCESS;
        }

        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => [
                    'node' => $node->name,
                    'warnings' => $warnings,
                ],
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
