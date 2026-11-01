<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $activity_cursor
 * @property string|null $log_path
 * @property int|null $log_inode
 * @property int|null $log_offset
 * @property array<string,mixed>|null $observation
 */
final class ProblemCollectorState extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'observation',
        'activity_cursor',
        'log_path',
        'log_inode',
        'log_offset',
    ];

    /** @var string */
    #[\Override]
    protected $table = 'problem_collector_state';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'observation' => 'array',
            'activity_cursor' => 'integer',
            'log_inode' => 'integer',
            'log_offset' => 'integer',
        ];
    }
}
