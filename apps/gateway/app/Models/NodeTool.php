<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NodeToolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $node_id
 * @property string $name
 * @property string|null $instance_key
 * @property string|null $version_family
 * @property string|null $runtime
 * @property array<string, mixed>|null $runtime_config
 * @property string $expected_state
 * @property string|null $expected_version
 * @property array<string, mixed>|null $config
 * @property array<string, mixed>|null $credentials
 * @property-read Node|null $node
 */
class NodeTool extends Model
{
    /** @use HasFactory<NodeToolFactory> */
    use HasFactory;

    private const array DOCKER_RUNTIME_TOOLS = [
        'mailpit',
        'mysql',
        'postgres',
        'redis',
        'reverb',
        'rustfs',
    ];

    #[\Override]
    protected $fillable = [
        'node_id',
        'name',
        'instance_key',
        'version_family',
        'runtime',
        'runtime_config',
        'expected_state',
        'expected_version',
        'config',
        'credentials',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'runtime_config' => 'array',
            'credentials' => 'encrypted:array',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (NodeTool $tool): void {
            $name = (string) $tool->name;

            if ($name === '') {
                return;
            }

            $tool->instance_key ??= self::defaultInstanceKey($name);
            $tool->runtime ??= self::defaultRuntimeForTool($name);
        });
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public static function defaultInstanceKey(string $name): string
    {
        return "{$name}:default";
    }

    public static function defaultRuntimeForTool(string $name): ?string
    {
        return in_array($name, self::DOCKER_RUNTIME_TOOLS, true) ? 'docker' : null;
    }
}
