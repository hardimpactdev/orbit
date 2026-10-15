<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named set of Conn settings that the Nodes bound to it share.
 *
 * @property int $id
 * @property string $name
 * @property array<string, mixed> $settings
 * @property int $settings_version
 * @property array<string, array{version: int, updated_at: string, updated_by_node_id: int|null}>|null $section_meta
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, ConnProfileBinding> $bindings
 */
final class ConnProfile extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['name', 'settings', 'settings_version', 'section_meta'];

    /** @return HasMany<ConnProfileBinding, $this> */
    public function bindings(): HasMany
    {
        return $this->hasMany(ConnProfileBinding::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'settings_version' => 'integer',
            'section_meta' => 'array',
        ];
    }
}
