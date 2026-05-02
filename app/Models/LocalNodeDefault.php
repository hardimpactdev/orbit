<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalNodeDefault extends Model
{
    protected $fillable = [
        'default_node_name',
    ];
}
