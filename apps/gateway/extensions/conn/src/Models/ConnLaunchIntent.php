<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $operation_id
 * @property string $request
 * @property string|null $dispatch
 */
final class ConnLaunchIntent extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'operation_id';

    protected $fillable = ['operation_id', 'request', 'dispatch'];

    protected $hidden = ['request', 'dispatch'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['operation_id' => 'integer', 'request' => 'encrypted', 'dispatch' => 'encrypted'];
    }
}
