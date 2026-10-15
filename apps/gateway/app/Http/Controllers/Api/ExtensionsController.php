<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Extensions\DisableExtensionAction;
use App\Actions\Extensions\EnableExtensionAction;
use App\Domain\Extensions\ExtensionRegistry;
use App\Domain\Extensions\ExtensionStore;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class ExtensionsController extends Controller
{
    public function index(Request $request, ExtensionStore $extensions): JsonResponse
    {
        return response()->json(['data' => $extensions->all(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function enable(Request $request, string $extension, ExtensionRegistry $registry, EnableExtensionAction $enable): JsonResponse
    {
        $this->validateExtension($extension, $registry);
        $enable->handle($extension);

        return response()->json(['data' => ['name' => $extension, 'enabled' => true], 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function disable(Request $request, string $extension, ExtensionRegistry $registry, DisableExtensionAction $disable): JsonResponse
    {
        $this->validateExtension($extension, $registry);
        $disable->handle($extension);

        return response()->json(['data' => ['name' => $extension, 'enabled' => false], 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    private function validateExtension(string $extension, ExtensionRegistry $registry): void
    {
        validator(['extension' => $extension], ['extension' => ['required', Rule::in($registry->names())]])->validate();
    }
}
