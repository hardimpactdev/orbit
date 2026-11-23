<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Models;

use App\Models\Node;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An iOS device registered by a Conn Node to receive APNs attention pushes. The APNs token is stored encrypted and never exposed.
 *
 * @property int $id
 * @property int $node_id
 * @property string $device_id
 * @property string $apns_token
 * @property string $apns_environment
 * @property string $bundle_id
 * @property string $app_version
 * @property string $ios_version
 * @property string $locale
 * @property array{approval: bool, input: bool, turn_finished: bool, turn_failed: bool, hide_titles: bool} $preferences
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Node|null $node
 */
final class ConnPushDevice extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'node_id',
        'device_id',
        'apns_token',
        'apns_environment',
        'bundle_id',
        'app_version',
        'ios_version',
        'locale',
        'preferences',
    ];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['apns_token'];

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function allowsKind(string $kind): bool
    {
        return ($this->preferences[$kind] ?? false) === true;
    }

    public function hidesTitles(): bool
    {
        return ($this->preferences['hide_titles'] ?? false) === true;
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'node_id' => 'integer',
            'apns_token' => 'encrypted',
            'preferences' => 'array',
        ];
    }
}
