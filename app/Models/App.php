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
 * @property-read Node|null $node
 * @property-read Collection<int, Process> $processes
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
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'adopted' => 'boolean',
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
