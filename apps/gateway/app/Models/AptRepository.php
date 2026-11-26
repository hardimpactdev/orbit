<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $node_id
 * @property string $name
 * @property array<string, mixed> $definition
 * @property bool $verified
 * @property list<string>|null $entries every `uri suite` this source may have on disk
 * @property array<int|string, list<string>>|null $writers each open Operation that may write it, with the entries it may publish
 * @property array<string, mixed>|null $settled the newest settled end: verified, definition, entries, operation
 */
final class AptRepository extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['node_id', 'name', 'definition', 'verified', 'entries', 'writers', 'settled'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['definition' => 'array', 'verified' => 'boolean', 'entries' => 'array', 'writers' => 'array', 'settled' => 'array'];
    }
}
