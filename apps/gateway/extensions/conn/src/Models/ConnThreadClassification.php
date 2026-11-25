<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The librarian's receipt survives a thread leaving the live index upon archiving.
 *
 * @property array{result: 'existing', project_id: int, reason: string}|array{result: 'new', name: string, slug: string}|array{result: 'none'}|null $result
 * @property Carbon|null $completed_at
 */
final class ConnThreadClassification extends Model
{
    #[\Override]
    public $timestamps = false;

    /** @var list<string> */
    #[\Override]
    protected $fillable = ['conn_environment_id', 'thread_id', 'result', 'completed_at'];

    /** @return Builder<self> */
    public static function forThread(int $environmentId, string $threadId): Builder
    {
        return self::query()->where('conn_environment_id', $environmentId)->where('thread_id', $threadId);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['result' => 'array', 'completed_at' => 'datetime'];
    }
}
