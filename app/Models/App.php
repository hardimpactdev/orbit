<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property string $name
 * @property int $node_id
 * @property string $environment
 * @property string|null $domain
 * @property string $path
 * @property string $document_root
 * @property string|null $repository
 * @property string $php_version
 * @property bool $adopted
 * @property array<string, mixed>|null $agent_ide_config
 * @property-read Node|null $node
 * @property-read Collection<int, Process> $processes
 * @property-read Collection<int, Schedule> $schedules
 * @property-read Collection<int, Workspace> $workspaces
 */
class App extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'node_id',
        'environment',
        'domain',
        'path',
        'document_root',
        'repository',
        'php_version',
        'adopted',
        'agent_ide_config',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'adopted' => 'boolean',
            'agent_ide_config' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return HasMany<Process, $this>
     */
    public function processes(): HasMany
    {
        return $this->hasMany(Process::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<Schedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class)->orderBy('name');
    }

    /**
     * @return HasMany<Workspace, $this>
     */
    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class)->orderBy('name');
    }

    public function url(): string
    {
        if (is_string($this->domain) && $this->domain !== '') {
            return "https://{$this->domain}";
        }

        $this->loadMissing('node');

        $tld = is_string($this->node?->tld) ? trim($this->node->tld, '.') : '';

        if ($tld === '') {
            return "https://{$this->name}";
        }

        return "https://{$this->name}.{$tld}";
    }

    public function documentRootPath(): string
    {
        $root = trim((string) $this->document_root, '/');

        if ($root === '') {
            return rtrim((string) $this->path, '/');
        }

        return Str::finish(rtrim((string) $this->path, '/'), '/').$root;
    }
}
