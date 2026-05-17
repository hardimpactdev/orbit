<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Apps\CreateAppSourceOnNode;
use App\Actions\Apps\EnactAppRuntime;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Support\GitRepositoryReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AppStoreController implements Loggable
{
    private const array SUPPORTED_PHP_VERSIONS = ['8.5'];

    private ?App $activitySubject = null;

    public function __construct(
        private readonly NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function __invoke(Request $request, CreateAppSourceOnNode $createAppSourceOnNode, EnactAppRuntime $enactAppRuntime): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        if ($caller->role === 'app') {
            return $this->error('caller_role_not_allowed', 'This command may only be run from a control or gateway node.', ['caller_role' => 'app'], 403);
        }

        $input = $this->validatedInput($request);

        if ($input instanceof JsonResponse) {
            return $input;
        }

        $requiredRole = $input['domain'] !== null ? 'app-production' : 'app-development';
        $node = $this->resolveTargetNode($input['node'], $requiredRole);

        if ($node instanceof JsonResponse) {
            return $node;
        }

        if (! $this->callerCanCreateOnNode($caller, $node)) {
            return $this->error('authorization_failed', "This node is not authorized to create apps on '{$node->name}'.", ['node' => $node->name], 403);
        }

        $existingApp = App::query()->with('node')->where('name', $input['name'])->first();

        if ($existingApp instanceof App) {
            return $this->error('app.collision', "App name '{$input['name']}' is already registered in the gateway app registry on node '{$existingApp->node?->name}'.", [
                'name' => $input['name'],
                'node' => $existingApp->node?->name,
            ], 409);
        }

        $routeDomain = $this->proxyRouteDomain($input, $node);
        $existingRoute = ProxyRoute::query()
            ->where('domain', $routeDomain)
            ->first();

        if ($existingRoute instanceof ProxyRoute) {
            return $this->error('proxy.domain_conflict', "Proxy route domain '{$routeDomain}' is already registered.", [
                'domain' => $routeDomain,
                'owner_type' => $existingRoute->owner_type,
                'kind' => $existingRoute->kind,
            ], 409);
        }

        $source = $createAppSourceOnNode->handle($node, $input['name'], $input['repository'], $input['domain']);

        if (! $source['result']->successful()) {
            return $this->error('app.source_creation_failed', "Source creation for app '{$input['name']}' failed on node '{$node->name}'.", [
                'reason' => trim($source['result']->output()) ?: 'source creation failed',
                ...($input['repository'] !== null ? ['transport' => GitRepositoryReference::transport($input['repository'])] : []),
            ], 500);
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
        $this->activitySubject = $app;
        $warnings = $enactAppRuntime->handle($app);

        return response()->json([
            'success' => [
                'data' => [
                    'result' => ['action' => 'created'],
                    'app' => $this->appPayload($app),
                ],
                'meta' => ['warnings' => $warnings],
            ],
        ]);
    }

    /**
     * @return array{name: string, node: string, repository: ?string, root: string, php_version: string, domain: ?string}|JsonResponse
     */
    private function validatedInput(Request $request): array|JsonResponse
    {
        $name = $this->stringInput($request, 'name');
        $node = $this->stringInput($request, 'node');
        $repository = GitRepositoryReference::canonicalize($this->stringInput($request, 'repository'));
        $root = $this->stringInput($request, 'root') ?? 'public';
        $phpVersion = $this->stringInput($request, 'php_version') ?? '8.5';
        $domain = $this->stringInput($request, 'domain');

        if ($name === null) {
            return $this->validationFailed('name', 'App name is required.');
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name) || mb_strlen($name) > 40) {
            return $this->validationFailed('name', 'App name must be a slug of 40 characters or fewer.');
        }

        if ($node === null) {
            return $this->validationFailed('node', 'The node field is required.');
        }

        if ($repository === false) {
            return $this->validationFailed('repository', 'Repository must be a full Git URL or GitHub owner/repo shorthand.');
        }

        if ($root === '' || preg_match('/[\x00-\x1F;`$|&<>"\'\\\\]/', $root)) {
            return $this->validationFailed('root', 'Document root is invalid.');
        }

        if (! in_array($phpVersion, self::SUPPORTED_PHP_VERSIONS, true)) {
            return $this->validationFailed('php_version', 'Unsupported PHP version.');
        }

        if ($domain !== null && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return $this->validationFailed('domain', 'Production domain is invalid.');
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

    private function resolveTargetNode(string $nodeName, string $requiredRole): Node|JsonResponse
    {
        $node = Node::query()->where('name', $nodeName)->first();

        if (! $node instanceof Node) {
            return $this->validationFailed('node', "Node '{$nodeName}' was not found.");
        }

        if ($node->status !== 'active' || ! $this->nodeRoleAssignments->nodeHasActiveRole($node, $requiredRole)) {
            return $this->error('app.ineligible_node', "Node '{$node->name}' is not an active app node.", [
                'node' => $node->name,
                'required_role' => $requiredRole,
                'status' => $node->status,
            ], 400);
        }

        return $node;
    }

    private function callerCanCreateOnNode(Node $caller, Node $node): bool
    {
        if ($caller->role === 'gateway') {
            return true;
        }

        return DB::table('node_access')
            ->where('consumer_node_id', $caller->id)
            ->where('serving_node_id', $node->id)
            ->exists();
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

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
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

    private function validationFailed(string $field, string $message): JsonResponse
    {
        return $this->error('validation_failed', $message, ['field' => $field], 400);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:POST /apps';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [];
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        return null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
