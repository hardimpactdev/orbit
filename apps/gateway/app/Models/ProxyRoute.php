<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\ProxyRouteOwnerInvariantViolation;
use App\Services\Proxy\InstanceProxyRouteOwnershipResolver;
use App\Services\Proxy\NonInstanceProxyRouteOwnership;
use App\Services\Proxy\WorkspaceProxyRouteOwnership;
use App\Services\Proxy\WorkspaceProxyRouteOwnershipResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $node_id
 * @property string $domain
 * @property int|null $app_id
 * @property int|null $workspace_id
 * @property int|null $instance_id
 * @property string $owner_type
 * @property string $kind
 * @property string $source_hash
 * @property array<string, mixed>|null $config
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Node $node
 * @property-read App|null $app
 * @property-read Workspace|null $workspace
 * @property-read Instance|null $instance
 *
 * Instance-backed routes derive App ownership through instance.app. The
 * retained app_id must match instance.app_id and is compatibility data only.
 */
class ProxyRoute extends Model
{
    use HasFactory;

    #[Override]
    protected static function booted(): void
    {
        static::saving(static function (ProxyRoute $route): void {
            $route->assertOwnerInvariant();
        });
    }

    #[Override]
    protected $fillable = [
        'node_id',
        'domain',
        'app_id',
        'workspace_id',
        'instance_id',
        'owner_type',
        'kind',
        'source_hash',
        'config',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }

    public function assertOwnerInvariant(): void
    {
        $this->unsetRelation('instance');
        $this->unsetRelation('workspace');
        $this->unsetRelation('app');

        if (InstanceProxyRouteOwnershipResolver::isDirectOwner($this->owner_type)) {
            if ((new InstanceProxyRouteOwnershipResolver)->resolve($this) instanceof Instance) {
                return;
            }

            throw new ProxyRouteOwnerInvariantViolation(
                "Proxy route '{$this->domain}' has invalid {$this->owner_type} ownership.",
            );
        }

        if ($this->owner_type === 'workspace') {
            if ((new WorkspaceProxyRouteOwnershipResolver)->resolve($this) instanceof WorkspaceProxyRouteOwnership) {
                return;
            }

            throw new ProxyRouteOwnerInvariantViolation(
                "Proxy route '{$this->domain}' has conflicting workspace ownership.",
            );
        }

        if (NonInstanceProxyRouteOwnership::supports($this->owner_type)) {
            if ($this->instance_id === null && $this->app_id === null && $this->workspace_id === null) {
                return;
            }

            throw new ProxyRouteOwnerInvariantViolation(
                "Proxy route '{$this->domain}' is {$this->owner_type}-owned but identifies an instance, app, or workspace.",
            );
        }

        throw new ProxyRouteOwnerInvariantViolation(
            "Proxy route '{$this->domain}' has an unknown owner type '{$this->owner_type}'.",
        );
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Instance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }
}
