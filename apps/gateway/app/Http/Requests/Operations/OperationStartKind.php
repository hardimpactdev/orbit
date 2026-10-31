<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use Illuminate\Http\Request;

interface OperationStartKind
{
    public function resolve(Request $request): string;
}
