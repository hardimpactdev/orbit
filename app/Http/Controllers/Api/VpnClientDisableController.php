<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VpnClientDisableController extends VpnClientEnableController
{
    #[\Override]
    public function __invoke(Request $request, string $name): JsonResponse
    {
        return $this->respond($this->manager()->disable($name, $request->string('totp')->trim()->toString() ?: null));
    }
}
