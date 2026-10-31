<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Domain\Instances\InstanceDestroyKind;
use App\Http\Requests\Operations\OperationStartKind;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Http\Request;

final readonly class InstanceDestroyStartKind implements OperationStartKind
{
    public function resolve(Request $request): string
    {
        $previous = Operation::query()->whereIn('kind', ['instance.destroy', 'instance.destroy_development'])
            ->where('idempotency_key', $request->header('Idempotency-Key'))->first();

        if ($previous !== null) {
            return $previous->kind;
        }

        return InstanceDestroyKind::forInstance(Instance::query()->whereKey($request->route('instance'))->firstOrFail());
    }
}
