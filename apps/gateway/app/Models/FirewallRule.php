<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Firewall\FirewallAction;
use App\Domain\Shared\LifecycleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $node_id
 * @property string|null $label
 * @property FirewallAction $action
 * @property string $source
 * @property string $protocol
 * @property string $port
 * @property LifecycleStatus $status
 * @property string|null $failed_step
 * @property string|null $error_code
 * @property-read Node $node
 */
final class FirewallRule extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'node_id',
        'label',
        'action',
        'source',
        'protocol',
        'port',
        'status',
        'failed_step',
        'error_code',
    ];

    /** The ufw comment: for people only; rows are matched by their tuple. */
    public function comment(): string
    {
        $label = trim(substr(preg_replace('/\s+/', ' ', preg_replace('/[^\x20-\x7e]|[\'"]/', '', (string) $this->label) ?? '') ?? '', 0, 64));

        return $label === '' ? 'orbit' : 'orbit '.$label;
    }

    /** The rule's identity on its Node, as people read it. */
    public function identity(): string
    {
        return "{$this->port}/{$this->protocol} from {$this->source}";
    }

    /** @return list<'tcp'|'udp'> */
    public function protocols(): array
    {
        return $this->protocol === 'any' ? ['tcp', 'udp'] : [$this->protocol === 'udp' ? 'udp' : 'tcp'];
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => FirewallAction::class,
            'status' => LifecycleStatus::class,
        ];
    }
}
