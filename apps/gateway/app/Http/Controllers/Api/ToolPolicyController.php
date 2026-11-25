<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tools\ManageToolPolicyAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tools\SetToolPolicyRequest;
use App\Http\Requests\Tools\ShowToolPolicyRequest;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class ToolPolicyController extends Controller
{
    public function show(ShowToolPolicyRequest $request, ManageToolPolicyAction $policies): JsonResponse
    {
        return response()->json(['data' => $policies->show(ValidatedData::nullableString($request->validated('export'))), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function update(SetToolPolicyRequest $request, ManageToolPolicyAction $policies): JsonResponse
    {
        return response()->json(['data' => $policies->change($request->string('mode')->value(), ValidatedData::nullableString($request->validated('catalog_path'))),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
