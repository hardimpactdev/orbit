<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalNodeDefault extends Model
{
    #[\Override]
    protected $fillable = [
        'default_node_name',
    ];
}
