<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One item the Gateway published to Vaultwarden. `fingerprint` is a keyed hash of the item content,
 * so a sync rewrites only items whose content changed.
 *
 * @property int $id
 * @property string $source
 * @property string $cipher_id
 * @property string $fingerprint
 * @property string|null $revision_date
 */
final class VaultItem extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'source',
        'cipher_id',
        'fingerprint',
        'revision_date',
    ];
}
